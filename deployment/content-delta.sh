#!/usr/bin/env bash
# Runs ON STAGING. Works out the smallest set of row changes that would make
# production's content tables match staging's, and writes them as one SQL file.
#
# Why checksums and not timestamps: post_modified only moves when WordPress
# updates a post. It says nothing about rows a plugin wrote directly, and
# nothing at all about rows that were DELETED — a timestamp-based diff silently
# leaves deleted content live on production. Hashing every row on both sides
# catches all three cases.
#
# Both hostnames collapse to one token before hashing. The two sides are meant
# to differ by domain, and without this every row containing a link looks
# changed and the diff degenerates into a full copy.
#
# Job applications are excluded end to end. They exist only on production, so to
# a diff they look like rows staging deleted — acting on that would delete real
# leads.
#
# Usage:
#   content-delta.sh <prod-sums.tsv.gz> <out-delta.sql.gz> <prefix> <stg-url> <prod-url> <max-ship>
#
# Prints one "table ship=N del=N live=N" line per table, then a TOTAL line, then
# either "VERDICT ok" or "VERDICT too-big" — in which case no delta is written
# and the caller should fall back to copying the tables wholesale.

set -Eeuo pipefail

SUMS="${1:?prod sums file required}"
OUT="${2:?output file required}"
PREFIX="${3:?prefix required}"
STG_URL="${4:?staging url required}"
PROD_URL="${5:?production url required}"
MAX_SHIP="${6:-20000}"

STACK_DIR="${STACK_DIR:-/opt/yesgermany/staging}"
cd "$STACK_DIR"
set -a; . ./.env; set +a

# stderr is discarded: the client prints a password warning on every call, and
# the caller parses this script's stdout.
my()     { docker compose exec -T db mysql -u root -p"$DB_ROOT_PASSWORD" -N --batch "$DB_NAME" 2>/dev/null; }
mydump() { docker compose exec -T db mysqldump -u root -p"$DB_ROOT_PASSWORD" "$@" 2>/dev/null; }

TABLES="posts postmeta terms termmeta term_taxonomy term_relationships"

# Primary key expression. term_relationships has a composite key, so it is
# keyed on the pair.
keyexpr() {
  case "$1" in
    posts)              echo "ID" ;;
    postmeta|termmeta)  echo "meta_id" ;;
    terms)              echo "term_id" ;;
    term_taxonomy)      echo "term_taxonomy_id" ;;
    term_relationships) echo "CONCAT(object_id,'-',term_taxonomy_id)" ;;
  esac
}

# Postmeta that is derived or belongs to production. Must match the list in
# content-sums.sql.tpl exactly, or the two sides hash different row sets and
# every publish looks like a large change.
#
#   _elementor_css / _elementor_element_cache / _elementor_page_assets
#       generated caches, rebuilt on demand. Left in, a no-op publish showed 70
#       changed rows because production regenerates them after each import.
#   ekit_post_views_count
#       live visitor counters — production's own data. The full-table push has
#       been overwriting these with staging's counts on every publish.
#   _edit_lock / _edit_last
#       who has the post open in wp-admin.
META_EXCL="'_elementor_css','_elementor_element_cache','_elementor_page_assets','ekit_post_views_count','_edit_lock','_edit_last'"

# Rows that must never take part in the diff.
whereclause() {
  case "$1" in
    posts)    echo "post_type<>'awsm_job_application'" ;;
    postmeta) echo "post_id NOT IN (SELECT ID FROM ${PREFIX}posts WHERE post_type='awsm_job_application') AND meta_key NOT IN (${META_EXCL})" ;;
    *)        echo "1=1" ;;
  esac
}

# ---------------------------------------------------------------------------
# 1. Load production's checksums.
#
# Converted to multi-row INSERTs rather than LOAD DATA INFILE, which needs
# local_infile enabled server-side — changing MySQL configuration to run a
# deploy is not a trade worth making.
# ---------------------------------------------------------------------------
{
  echo "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION';"
  for t in $TABLES; do
    echo "DROP TABLE IF EXISTS _yg_psum_${t};"
    echo "CREATE TABLE _yg_psum_${t} (k VARBINARY(64) NOT NULL, h CHAR(32) NOT NULL, PRIMARY KEY(k)) ENGINE=InnoDB;"
  done
  gzip -dc "$SUMS" | awk -F'\t' '
    NF < 3 { next }
    $1 != cur { if (n>0) { print ";"; n=0 } cur=$1 }
    {
      if (n==0) { printf "INSERT INTO _yg_psum_%s VALUES ", cur; sep="" } else sep=","
      printf "%s(%s%s%s,%s%s%s)", sep, "'"'"'", $2, "'"'"'", "'"'"'", $3, "'"'"'"
      if (++n >= 2000) { print ";"; n=0 }
    }
    END { if (n>0) print ";" }
  '
} | my

# ---------------------------------------------------------------------------
# 2. Checksum staging the same way, then derive what to ship and what to delete.
#
# The column list comes from information_schema ordered by position, so both
# sides hash identical inputs without hardcoding column names. If the schemas
# ever diverge, every row mismatches, the delta blows past its ceiling and the
# caller falls back to a full copy — the right outcome for a schema change.
# ---------------------------------------------------------------------------
for t in $TABLES; do
  K="$(keyexpr "$t")"
  W="$(whereclause "$t")"
  # These go inside a SQL string literal that is then PREPAREd, so every single
  # quote in them has to be doubled. Without this the first quote in
  # post_type<>'awsm_job_application' closes the literal early and the statement
  # fails to parse.
  K_ESC="${K//\'/\'\'}"
  W_ESC="${W//\'/\'\'}"
  cat <<SQL | my
SET SESSION group_concat_max_len=1000000;
SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION';
DROP TABLE IF EXISTS _yg_ssum_${t};
CREATE TABLE _yg_ssum_${t} (k VARBINARY(64) NOT NULL, h CHAR(32) NOT NULL, PRIMARY KEY(k)) ENGINE=InnoDB;
SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(\`',column_name,'\`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='${PREFIX}${t}');
SET @q := CONCAT('INSERT INTO _yg_ssum_${t} (k,h) SELECT ${K_ESC}, MD5(REPLACE(REPLACE(CONCAT_WS(''|'',',
                 @cols, '),''${STG_URL}'',''@''),''${PROD_URL}'',''@'')) FROM ${PREFIX}${t} WHERE ${W_ESC}');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

DROP TABLE IF EXISTS _yg_ship_${t};
CREATE TABLE _yg_ship_${t} (k VARBINARY(64) NOT NULL, PRIMARY KEY(k)) ENGINE=InnoDB;
INSERT INTO _yg_ship_${t} (k)
  SELECT s.k FROM _yg_ssum_${t} s LEFT JOIN _yg_psum_${t} p ON p.k=s.k
   WHERE p.k IS NULL OR p.h<>s.h;

DROP TABLE IF EXISTS _yg_del_${t};
CREATE TABLE _yg_del_${t} (k VARBINARY(64) NOT NULL, PRIMARY KEY(k)) ENGINE=InnoDB;
INSERT INTO _yg_del_${t} (k)
  SELECT p.k FROM _yg_psum_${t} p LEFT JOIN _yg_ssum_${t} s ON s.k=p.k
   WHERE s.k IS NULL;
SQL
done

# ---------------------------------------------------------------------------
# 3. Decide before doing the expensive part.
# ---------------------------------------------------------------------------
TOT_SHIP=0; TOT_DEL=0; TOT_LIVE=0
for t in $TABLES; do
  read -r ship del live <<< "$(printf "SELECT (SELECT COUNT(*) FROM _yg_ship_%s),(SELECT COUNT(*) FROM _yg_del_%s),(SELECT COUNT(*) FROM _yg_ssum_%s);\n" \
    "$t" "$t" "$t" | my | tr '\t' ' ')"
  echo "${t} ship=${ship:-0} del=${del:-0} live=${live:-0}"
  TOT_SHIP=$((TOT_SHIP + ${ship:-0})); TOT_DEL=$((TOT_DEL + ${del:-0})); TOT_LIVE=$((TOT_LIVE + ${live:-0}))
done
echo "TOTAL ship=${TOT_SHIP} del=${TOT_DEL} live=${TOT_LIVE}"

if [[ $((TOT_SHIP + TOT_DEL)) -gt "$MAX_SHIP" ]]; then
  echo "VERDICT too-big"
  exit 0
fi

# ---------------------------------------------------------------------------
# 4. Emit the delta.
#
# The key lists travel inside the file: production has no _yg_* tables of its
# own, so the DELETEs would have nothing to join against otherwise.
#
# Delete-then-insert per key rather than REPLACE INTO: REPLACE deletes by
# PRIMARY KEY *and every unique key*, which on postmeta would quietly remove
# unrelated rows. One transaction, so a failure part-way leaves production on
# the old content rather than half of each.
# ---------------------------------------------------------------------------
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

{
  echo "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION';"
  echo "SET autocommit=0;"
  echo "START TRANSACTION;"

  for t in $TABLES; do
    echo "DROP TEMPORARY TABLE IF EXISTS _yg_k_${t};"
    echo "CREATE TEMPORARY TABLE _yg_k_${t} (k VARBINARY(64) NOT NULL, PRIMARY KEY(k)) ENGINE=InnoDB;"
    printf "SELECT k FROM _yg_ship_%s UNION SELECT k FROM _yg_del_%s;\n" "$t" "$t" | my \
      | awk -v t="$t" '
          { if (n==0) { printf "INSERT INTO _yg_k_%s VALUES ", t; sep="" } else sep=","
            printf "%s('"'"'%s'"'"')", sep, $1
            if (++n >= 2000) { print ";"; n=0 } }
          END { if (n>0) print ";" }'
  done

  for t in $TABLES; do
    K="$(keyexpr "$t")"
    if [[ "$t" == "term_relationships" ]]; then
      echo "DELETE x FROM ${PREFIX}${t} x JOIN _yg_k_${t} d ON d.k=CONCAT(x.object_id,'-',x.term_taxonomy_id);"
    else
      echo "DELETE x FROM ${PREFIX}${t} x JOIN _yg_k_${t} d ON d.k=x.${K};"
    fi
  done

  for t in $TABLES; do
    K="$(keyexpr "$t")"
    if [[ "$t" == "term_relationships" ]]; then
      WHERE="CONCAT(object_id,'-',term_taxonomy_id) IN (SELECT k FROM _yg_ship_${t})"
    else
      WHERE="${K} IN (SELECT k FROM _yg_ship_${t})"
    fi
    mydump --no-create-info --skip-add-locks --skip-disable-keys --skip-comments \
           --skip-extended-insert --single-transaction --quick \
           --default-character-set=utf8mb4 \
           --where="$WHERE" "$DB_NAME" "${PREFIX}${t}" \
      | { grep -E '^INSERT INTO' || true; } \
      | sed "s|${STG_URL}|${PROD_URL}|g"
  done

  # Generated caches are excluded from the diff, so production still holds its
  # own — including rendered HTML for pages that just changed. Clearing them
  # here is what the full-table push achieved by replacing postmeta wholesale.
  # ekit_post_views_count is deliberately NOT cleared: those are live counters.
  echo "DELETE FROM ${PREFIX}postmeta WHERE meta_key IN ('_elementor_css','_elementor_element_cache','_elementor_page_assets');"

  for t in $TABLES; do echo "DROP TEMPORARY TABLE IF EXISTS _yg_k_${t};"; done
  echo "COMMIT;"
} > "${TMP}/delta.sql"

gzip -6 -c "${TMP}/delta.sql" > "$OUT"

# The two checksum tables are ~72k rows each and have served their purpose. The
# much smaller ship/del lists are left behind deliberately: when a publish looks
# wrong, "which rows did it decide to send" is the first question worth asking.
{
  for t in $TABLES; do
    echo "DROP TABLE IF EXISTS _yg_psum_${t};"
    echo "DROP TABLE IF EXISTS _yg_ssum_${t};"
  done
} | my

echo "VERDICT ok"
