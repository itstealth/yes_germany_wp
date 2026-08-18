#!/usr/bin/env bash
# Publish staging content to the live site.
#
# The orchestrator (your machine, or a CI runner) only issues commands and polls
# for status. Every heavy step runs on a server:
#
#   1. staging dumps content to a file ON staging
#   2. staging sends that file straight to production  (no relay in between)
#   3. production applies it detached, via remote-apply.sh
#
# A dropped connection at any point is harmless: the work either completes on
# the server or restores itself there. Earlier versions streamed the import
# through the orchestrating machine, so a dropped connection stopped it
# half-done — that is how 4 real job applications were lost.
#
# Usage: push-fast.sh [--dry-run]

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

DRY_RUN=false
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=true

STG_HOST="${STAGING_HOST:-100.66.61.11}"
STG_USER="${STAGING_USER:-ygdeploy}"
STG_PORT="${STAGING_PORT:-2222}"
STG_DIR="${STAGING_STACK_DIR:-/opt/yesgermany/staging}"
STG_URL="${STG_URL:-https://yg-staging.stealthlearn.in}"

PROD_HOST="${PROD_HOST:?PROD_HOST required}"
PROD_USER="${PROD_USER:?PROD_USER required}"
PROD_ROOT="${PROD_ROOT:?PROD_ROOT required}"
PROD_URL="${PROD_URL:-https://www.yesgermany.com}"
PROD_BACKUP_DIR="${PROD_BACKUP_DIR:?PROD_BACKUP_DIR required}"
PREFIX="${TABLE_PREFIX:-wpb9_}"

STG_KEY="${STAGING_SSH_KEY_FILE:-${HOME}/.ssh/deploy_key}"
PROD_KEY="${PROD_SSH_KEY_FILE:-${HOME}/.ssh/prod_deploy_key}"
SSHB="-o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=20 -o ServerAliveInterval=15"

# Reuse one connection per host for every command in this run.
#
# The script makes about fifteen SSH calls, and a fresh handshake to the cPanel
# host costs 4-5 seconds — roughly a minute of a two-minute publish spent
# reconnecting. With multiplexing the first call pays that once and the rest ride
# the open channel. ControlPersist keeps it briefly after the script exits so a
# retry does not pay it again either.
CM_DIR="${TMPDIR:-/tmp}/yg-cm-$$"
mkdir -p "$CM_DIR" && chmod 700 "$CM_DIR"
SSHB="${SSHB} -o ControlMaster=auto -o ControlPath=${CM_DIR}/%C -o ControlPersist=120"
cleanup_cm() {
  for s in "$CM_DIR"/*; do [[ -S "$s" ]] && ssh -O exit -o ControlPath="$s" x 2>/dev/null || true; done
  rm -rf "$CM_DIR" 2>/dev/null || true
}
trap cleanup_cm EXIT

stg()  { ssh -n -i "$STG_KEY"  -p "$STG_PORT" $SSHB "${STG_USER}@${STG_HOST}"  "$@"; }
prod() { ssh -n -i "$PROD_KEY" $SSHB "${PROD_USER}@${PROD_HOST}" "$@"; }

# SQL on stdin, tab-separated rows on stdout. Piping the statement in avoids the
# quoting minefield of embedding it in an -e argument: SQL containing single
# quotes silently truncated the statement when it was passed that way.
stg_sql() {
  ssh -i "$STG_KEY" -p "$STG_PORT" $SSHB "${STG_USER}@${STG_HOST}" \
    "cd '${STG_DIR}' && set -a && . ./.env && set +a && \
     docker compose exec -T db mysql -u root -p\"\$DB_ROOT_PASSWORD\" -N --batch \"\$DB_NAME\"" 2>/dev/null
}
prod_sql() {
  ssh -i "$PROD_KEY" $SSHB "${PROD_USER}@${PROD_HOST}" \
    "cd '${PROD_ROOT}' && wp db query --skip-column-names --skip-plugins --skip-themes" 2>/dev/null
}

[[ -f "$STG_KEY"  ]] || die "staging key missing: ${STG_KEY}"
[[ -f "$PROD_KEY" ]] || die "production key missing: ${PROD_KEY}"

STAMP="$(date -u +%Y%m%d-%H%M%S)"
REMOTE_SQL="/home/${PROD_USER}/_yg_incoming-${STAMP}.sql.gz"
STATE="/home/${PROD_USER}/_yg_push-${STAMP}.state"

log "Publish: staging → live"
$DRY_RUN && warn "DRY RUN — nothing will be written"

# ---------------------------------------------------------------------------
# 1. Both ends are what we think they are
# ---------------------------------------------------------------------------
# Simple and quote-safe: just look for the staging marker in wp-config.
STG_ENV="$(stg "grep -c \"WP_ENVIRONMENT_TYPE', 'staging'\" '${STG_DIR}/wordpress/wp-config.php' 2>/dev/null" | tr -dc '0-9')"
[[ "${STG_ENV:-0}" -ge 1 ]] && STG_ENV=staging || STG_ENV=unknown
[[ "$STG_ENV" == "staging" ]] || die "Source reports '${STG_ENV}', not staging."
ok "source is staging"
prod "test -f '${PROD_ROOT}/wp-config.php'" || die "No WordPress at ${PROD_ROOT}"
ok "target is production"

# ---------------------------------------------------------------------------
# 2. Has production been edited since the last publish?
#
# auto-draft rows are excluded: WordPress creates one the instant somebody
# clicks "Add New Post" in wp-admin, even if they type nothing. Counting those
# as "production was edited" blocked a legitimate publish. 'inherit' covers
# attachment/revision rows, 'trash' is not live content either.
#
# Compared against a watermark written by the previous push, not against
# staging's newest row. Comparing the two sides directly cannot tell "someone
# edited production" from "staging deleted something", so deletions always
# looked like a conflict.
# ---------------------------------------------------------------------------
WATERMARK="$(prod "cd '${PROD_ROOT}' && wp option get yg_last_push --skip-plugins --skip-themes 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]{4}-' | head -1 || true)"
# post_modified_gmt, not post_modified: the watermark is written with `date -u`,
# so comparing it against a site-local timestamp only works while the site is set
# to UTC. Both sites are today, but setting the WordPress timezone to IST would
# push every local timestamp 5.5h ahead of the watermark and block every publish.
PROD_NEWEST="$(prod "cd '${PROD_ROOT}' && wp db query \"SELECT MAX(post_modified_gmt) FROM ${PREFIX}posts WHERE post_type NOT IN ('revision','awsm_job_application') AND post_status NOT IN ('auto-draft','inherit','trash');\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]{4}-' | head -1)"

if [[ -n "$WATERMARK" ]]; then
  log "  last publish:      ${WATERMARK}"
  log "  production newest: ${PROD_NEWEST}"
  if [[ "$PROD_NEWEST" > "$WATERMARK" ]]; then
    if [[ "${FORCE_PUSH:-false}" == "true" ]]; then
      warn "Production edited since last publish — proceeding, that work will be lost."
    else
      die "Production was edited directly since the last publish (${PROD_NEWEST} > ${WATERMARK}).
   Bring it into staging first, or set FORCE_PUSH=true to discard it."
    fi
  else
    ok "no direct edits on production since last publish"
  fi
else
  warn "no watermark yet — this is the first publish"
fi

# ---------------------------------------------------------------------------
# 2b. What is this publish actually carrying?
#
# Without this the push reports success while saying nothing about its contents,
# so "my change didn't go live" is indistinguishable from "my change was no
# longer on staging when the push ran". That happened: an edit to the homepage
# headline was saved, then overwritten on staging by a later save four minutes
# before the push. The push was correct; there was simply nothing to carry. The
# operator had no way to see that.
#
# Read this list before approving. If the page you edited is not in it, staging
# does not hold your change — re-check it in the editor rather than re-pushing.
# ---------------------------------------------------------------------------
SINCE="${WATERMARK:-1970-01-01 00:00:00}"
MANIFEST="$(printf "SELECT p.ID, p.post_type, p.post_status, p.post_modified_gmt, LEFT(p.post_title,52)
  FROM %sposts p
  WHERE p.post_modified_gmt > '%s'
    AND p.post_type NOT IN ('revision','awsm_job_application')
    AND p.post_status NOT IN ('auto-draft','inherit','trash')
  ORDER BY p.post_modified_gmt DESC LIMIT 60;\n" "$PREFIX" "$SINCE" | stg_sql | tr -d '\r' || true)"

CHANGED_IDS="$(printf '%s\n' "$MANIFEST" | awk -F'\t' 'NF>1 && $1 ~ /^[0-9]+$/ {print $1}')"
CHANGED_N="$(printf '%s\n' "$CHANGED_IDS" | grep -c . || true)"

if [[ "${CHANGED_N:-0}" -eq 0 ]]; then
  warn "staging has no content changes since ${SINCE} — this publish would carry nothing new"
else
  log "Publishing ${CHANGED_N} changed item(s) since ${SINCE}:"
  printf '%s\n' "$MANIFEST" | awk -F'\t' 'NF>1 {printf "      %-8s %-12s %-9s %s  %s\n", $1, $2, $3, $4, $5}'
fi

$DRY_RUN && { log "Dry run complete."; exit 0; }

# ---------------------------------------------------------------------------
# 3. Snapshot production — only the tables this publish replaces.
#
# This used to be `wp db export` of the whole database: 642 tables, 1,059 MB,
# several minutes, repeated on every publish. Only 178 of those tables belong to
# this site — the other 464 belong to eleven unrelated client sites sharing the
# database, so each publish also wrote a copy of other clients' data into this
# site's backup directory. That is a data-handling problem as much as a slow one.
#
# The push only ever replaces six tables (285 MB), so only those six need
# saving. They are copied inside the database: no dump, no gzip, no disk round
# trip. That makes the snapshot both the fastest thing to take and the fastest
# thing to undo — rollback-content.sh restores straight from it.
#
# A compressed copy of the same six tables is written afterwards, detached, so
# durable history still exists without sitting on the critical path.
# ---------------------------------------------------------------------------
CONTENT_TABLES="posts postmeta terms termmeta term_taxonomy term_relationships"
SNAP="${PREFIX}ygsnap_"
BDIR="${PROD_BACKUP_DIR}/${STAMP}"

log "Snapshotting the tables this publish replaces"
{
  # WordPress columns like posts.post_date carry DEFAULT '0000-00-00 00:00:00',
  # which strict mode rejects — CREATE TABLE ... LIKE re-creates the definition
  # and fails with "Invalid default value for 'post_date'". mysqldump relaxes
  # sql_mode for exactly this reason; so does this.
  printf "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION';\n"
  for t in $CONTENT_TABLES; do
    printf "DROP TABLE IF EXISTS %s%s;\nCREATE TABLE %s%s LIKE %s%s;\nINSERT INTO %s%s SELECT * FROM %s%s;\n" \
      "$SNAP" "$t" "$SNAP" "$t" "$PREFIX" "$t" "$SNAP" "$t" "$PREFIX" "$t"
  done
} | prod_sql || die "Snapshot failed — nothing was changed."

# Row-for-row, in one round trip. An incomplete snapshot is worse than none:
# it looks like a safety net and is not one.
SNAP_CHECK="$(for t in $CONTENT_TABLES; do
    printf "SELECT CONCAT('%s:',(SELECT COUNT(*) FROM %s%s),':',(SELECT COUNT(*) FROM %s%s));\n" \
      "$t" "$PREFIX" "$t" "$SNAP" "$t"
  done | prod_sql | tr -d '\r' | grep ':' || true)"

# Silence is not success. A failed DDL, a dropped connection or a SQL error all
# produce no rows, and an empty result previously sailed through this check as
# "no mismatches found" — reporting a snapshot that did not exist.
EXPECTED="$(printf '%s\n' $CONTENT_TABLES | grep -c .)"
GOT="$(printf '%s\n' "$SNAP_CHECK" | grep -c ':' || true)"
[[ "${GOT:-0}" -eq "${EXPECTED:-0}" ]] \
  || die "Snapshot check reported ${GOT:-0} of ${EXPECTED} tables — treating as failed. Nothing was changed."

BAD=""
while IFS=: read -r t live snapped; do
  [[ -n "$t" ]] || continue
  [[ "${live:-x}" == "${snapped:-y}" ]] || BAD="${BAD} ${t}(${snapped:-?}/${live:-?})"
done <<< "$SNAP_CHECK"
[[ -z "${BAD// /}" ]] || die "Snapshot incomplete:${BAD} — nothing was changed."
ok "snapshot taken ($(printf '%s\n' "$SNAP_CHECK" | awk -F: '{s+=$2} END {print s}') rows, in-database)"

# ---------------------------------------------------------------------------
# 4. Dump content on staging, into a file on staging
# ---------------------------------------------------------------------------
TBLS=""
# options is deliberately NOT pushed.
#
# It holds active_plugins and every credential on the site. Pushing it wholesale
# carried staging's state onto production: nine plugins switched off — including
# LiteSpeed Cache, Site Kit, MonsterInsights and Microsoft UET — and the API
# credentials that harden-staging.sh deletes from staging were copied over the
# live ones. The client's analytics and ad tracking were dead for ~13 hours
# before anyone noticed.
#
# Plugin settings therefore do not travel yet. Restoring that needs a per-row
# allowlist, not a table copy; until that exists, settings are changed on live
# or via migrations/.
for t in $CONTENT_TABLES; do TBLS="${TBLS} ${PREFIX}${t}"; done
STG_FILE="/tmp/yg-content-${STAMP}.sql.gz"
APPLY_SCRIPT="remote-apply.sh"
MODE=full

# ---------------------------------------------------------------------------
# 4a. Try to send only what changed.
#
# Copying all six tables moves 53 MB to alter one word. The delta is derived
# from a hash of every row on both sides — not from timestamps, which say
# nothing about rows a plugin wrote directly and nothing at all about rows that
# were deleted.
#
# Every failure here falls through to the full copy below. The differential path
# is an optimisation; it is never the only way content can reach production.
# Set PUSH_MODE=full to skip it.
# ---------------------------------------------------------------------------
if [[ "${PUSH_MODE:-diff}" == "diff" ]]; then
  DELTA_DONE=false
  META_EXCL="''_elementor_css'',''_elementor_element_cache'',''_elementor_page_assets'',''ekit_post_views_count'',''_edit_lock'',''_edit_last''"
  P_SUMS="/home/${PROD_USER}/_yg_sums-${STAMP}.tsv.gz"
  S_SUMS="/tmp/yg-prodsums-${STAMP}.tsv.gz"
  DELTA="/tmp/yg-delta-${STAMP}.sql.gz"

  log "Checksumming production content"
  SUMS_SQL="$(sed -e "s|__PREFIX__|${PREFIX}|g" -e "s|__STG_URL__|${STG_URL}|g" \
                  -e "s|__PROD_URL__|${PROD_URL}|g" -e "s|__META_EXCL__|${META_EXCL}|g" \
                  "${SCRIPT_DIR}/content-sums.sql.tpl")"
  # scp, not a pipe into prod(): prod() runs ssh -n, which points stdin at
  # /dev/null, so piping into it wrote a 0-byte file and the whole delta path
  # quietly degraded to a full copy — with every staging row looking new.
  printf '%s\n' "$SUMS_SQL" > "${CM_DIR}/sums.sql"

  # gzip -t proves the file is a valid archive, not that it holds anything: an
  # empty input still gzips to a valid ~20-byte file. Count the rows instead.
  P_ROWS=0
  if scp -q -i "$PROD_KEY" $SSHB "${CM_DIR}/sums.sql" "${PROD_USER}@${PROD_HOST}:/tmp/_yg_sums-${STAMP}.sql" \
     && prod "cd '${PROD_ROOT}' && wp db query --skip-column-names --skip-plugins --skip-themes \
              < /tmp/_yg_sums-${STAMP}.sql 2>/dev/null | gzip -6 > '${P_SUMS}'; rm -f /tmp/_yg_sums-${STAMP}.sql"; then
    P_ROWS="$(prod "gzip -dc '${P_SUMS}' 2>/dev/null | wc -l" | tr -dc '0-9')"
  fi
  if [[ "${P_ROWS:-0}" -ge 1000 ]]; then
    ok "production checksums: ${P_ROWS} rows ($(prod "du -h '${P_SUMS}' | cut -f1"))"

    # Staging pulls. Production cannot reach staging — it is not on the tailnet.
    if stg "scp -q -i ~/.ssh/prod_deploy_key -o BatchMode=yes -o StrictHostKeyChecking=yes \
              '${PROD_USER}@${PROD_HOST}:${P_SUMS}' '${S_SUMS}'"; then
      scp -q -i "$STG_KEY" -P "$STG_PORT" $SSHB "${SCRIPT_DIR}/content-delta.sh" \
          "${STG_USER}@${STG_HOST}:/tmp/_yg_content_delta-${STAMP}.sh"

      log "Computing the delta on staging"
      DSTATS="$(stg "chmod +x /tmp/_yg_content_delta-${STAMP}.sh && \
                     STACK_DIR='${STG_DIR}' /tmp/_yg_content_delta-${STAMP}.sh \
                       '${S_SUMS}' '${DELTA}' '${PREFIX}' '${STG_URL}' '${PROD_URL}' 20000" || true)"
      printf '%s\n' "$DSTATS" | grep -E '^(posts|postmeta|terms|termmeta|term_taxonomy|term_relationships|TOTAL) ' \
        | sed 's/^/      /' || true

      if printf '%s\n' "$DSTATS" | grep -q '^VERDICT ok'; then
        SHIP="$(printf '%s\n' "$DSTATS" | awk '/^TOTAL /{for(i=1;i<=NF;i++) if($i ~ /^ship=/){sub(/ship=/,"",$i); print $i}}')"
        DEL="$(printf '%s\n' "$DSTATS"  | awk '/^TOTAL /{for(i=1;i<=NF;i++) if($i ~ /^del=/){sub(/del=/,"",$i); print $i}}')"
        if [[ "$(( ${SHIP:-0} + ${DEL:-0} ))" -eq 0 ]]; then
          warn "no row-level differences — production already matches staging"
        fi
        if stg "test -s '${DELTA}' && gzip -t '${DELTA}'"; then
          STG_FILE="$DELTA"; APPLY_SCRIPT="remote-apply-delta.sh"; MODE=delta; DELTA_DONE=true
          ok "delta ready: ${SHIP:-0} row(s) to write, ${DEL:-0} to remove ($(stg "du -h '${DELTA}' | cut -f1"))"
        else
          warn "delta file missing or corrupt"
        fi
      else
        warn "delta too large or not computable — copying the tables instead"
      fi
    else
      warn "staging could not fetch production's checksums"
    fi
  else
    warn "production checksums unusable (${P_ROWS:-0} rows) — copying the tables instead"
  fi
  prod "rm -f '${P_SUMS}'" || true
  stg  "rm -f '${S_SUMS}' /tmp/_yg_content_delta-${STAMP}.sh" || true
fi

if [[ "$MODE" != "delta" ]]; then
  log "Dumping content on staging (full copy)"
  stg "cd '${STG_DIR}' && set -a && . ./.env && set +a && \
       docker compose exec -T db mysqldump -u root -p\"\$DB_ROOT_PASSWORD\" \
         --single-transaction --quick --default-character-set=utf8mb4 \
         \"\$DB_NAME\" ${TBLS} 2>/dev/null \
       | sed 's|${STG_URL}|${PROD_URL}|g' \
       | gzip -6 > '${STG_FILE}'" \
    || die "Could not dump content on staging."
  stg "gzip -t '${STG_FILE}'" || die "Staging dump is corrupt."
  SZ="$(stg "du -h '${STG_FILE}' | cut -f1")"
  ok "dumped ${SZ} (URLs already rewritten)"
fi

# ---------------------------------------------------------------------------
# 5. Straight from staging to production. No machine in between.
# ---------------------------------------------------------------------------
log "Sending to production (direct)"
SENT=false
for a in 1 2 3; do
  if stg "scp -q -i ~/.ssh/prod_deploy_key -o BatchMode=yes -o StrictHostKeyChecking=yes \
            '${STG_FILE}' '${PROD_USER}@${PROD_HOST}:${REMOTE_SQL}'"; then
    if prod "gzip -t '${REMOTE_SQL}'"; then SENT=true; ok "sent and verified (attempt ${a})"; break; fi
    warn "arrived corrupt, retrying"
  else
    warn "attempt ${a} failed, retrying"
  fi
  sleep 4
done
stg "rm -f '${STG_FILE}'" || true
$SENT || die "Could not send content to production. Nothing was changed."

# ---------------------------------------------------------------------------
# 6. Apply it on production, detached.
#
# Launched with setsid+nohup so it survives this connection closing. From here
# the orchestrator only reads the state file.
# ---------------------------------------------------------------------------
log "Applying on production (${MODE}, detached — safe to disconnect)"
scp -q -i "$PROD_KEY" $SSHB "${SCRIPT_DIR}/${APPLY_SCRIPT}" "${PROD_USER}@${PROD_HOST}:/home/${PROD_USER}/_yg_remote_apply.sh"
prod "chmod +x /home/${PROD_USER}/_yg_remote_apply.sh && : > '${STATE}' && \
      WP_ROOT='${PROD_ROOT}' setsid nohup /home/${PROD_USER}/_yg_remote_apply.sh \
        '${REMOTE_SQL}' '${PREFIX}' '${STATE}' >/dev/null 2>&1 < /dev/null &" || true

# Polled every 2s rather than every 5s. The apply finishes in about 30s, so a
# 5s interval added up to 5s of pure waiting after it was already done — and
# with a multiplexed connection each poll is nearly free.
for i in $(seq 1 300); do
  sleep 2
  S="$(prod "tail -1 '${STATE}' 2>/dev/null" | tr -d '\r')"
  case "$S" in
    DONE)   ok "applied"; break ;;
    FAILED) prod "cat '${STATE}'" | sed 's/^/    /'; die "Apply failed on production. Undo with: rollback-content.sh" ;;
    *)      [[ -n "$S" ]] && printf '\r    %-60s' "$S" ;;
  esac
  [[ $i -eq 300 ]] && die "Timed out waiting for production. Check ${STATE} on the server."
done
echo

# ---------------------------------------------------------------------------
# 7. Verify, then record the watermark
# ---------------------------------------------------------------------------
log "Verifying"
FAILED=0

# One query for every count, not one round trip each. These were four separate
# SSH calls; on this host each handshake costs about as long as the check itself.
V="$(printf "SELECT CONCAT('jobapps=',(SELECT COUNT(*) FROM %sposts WHERE post_type='awsm_job_application'));
SELECT CONCAT('comments=',(SELECT COUNT(*) FROM %scomments));
SELECT CONCAT('users=',(SELECT COUNT(*) FROM %susers));
SELECT CONCAT('siteurl=',(SELECT option_value FROM %soptions WHERE option_name='siteurl'));\n" \
  "$PREFIX" "$PREFIX" "$PREFIX" "$PREFIX" | prod_sql | tr -d '\r' || true)"

field() { printf '%s\n' "$V" | grep -m1 "^$1=" | cut -d= -f2-; }
for name in jobapps comments users; do
  v="$(field "$name")"
  [[ "${v:-0}" -gt 0 ]] && ok "${name}: ${v}" || { warn "${name}: ${v:-0}"; FAILED=$((FAILED+1)); }
done

LIVE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 30 "${PROD_URL}/" || echo 000)"
[[ "$LIVE" == "200" ]] && ok "live site HTTP 200" || { warn "live site HTTP ${LIVE}"; FAILED=$((FAILED+1)); }

SURL="$(field siteurl)"
[[ "$SURL" == *stealthlearn* ]] && die "production siteurl is '${SURL}' — RUN rollback-content.sh NOW"
[[ -n "$SURL" ]] && ok "siteurl intact: ${SURL}" || { warn "could not read siteurl"; FAILED=$((FAILED+1)); }

# ---------------------------------------------------------------------------
# 7b. Prove the changed items actually match on both sides.
#
# The checks above prove the site is up and nothing was destroyed. They do not
# prove the edit travelled. This fingerprints every item in the manifest on both
# servers — title, status, content and the Elementor design — and compares them.
# A mismatch here means the publish did not land; equal fingerprints are proof
# that it did.
# ---------------------------------------------------------------------------
if [[ "${CHANGED_N:-0}" -gt 0 ]]; then
  log "Comparing published content"
  ID_LIST="$(printf '%s\n' "$CHANGED_IDS" | paste -sd, -)"
  # The two sides are meant to differ by domain — the dump rewrites staging URLs
  # to production ones on the way over. Both hostnames collapse to the same token
  # before hashing, so only real content differences show up. Without this every
  # page carrying a link mismatches and the check is pure noise.
  FP_SQL="$(printf "SELECT p.ID, MD5(CONCAT_WS('|', p.post_title, p.post_status,
      REPLACE(REPLACE(p.post_content,'%s','@'),'%s','@'),
      REPLACE(REPLACE(IFNULL((SELECT m.meta_value FROM %spostmeta m
        WHERE m.post_id=p.ID AND m.meta_key='_elementor_data'),''),'%s','@'),'%s','@')))
    FROM %sposts p WHERE p.ID IN (%s) ORDER BY p.ID;\n" \
    "$STG_URL" "$PROD_URL" "$PREFIX" "$STG_URL" "$PROD_URL" "$PREFIX" "$ID_LIST")"

  # wp db query emits a trailing blank line; keep only real rows or comm reports
  # a phantom difference.
  printf '%s' "$FP_SQL" | stg_sql  | tr -d '\r' | grep -E '^[0-9]+' | sort > "${TMPDIR:-/tmp}/yg-fp-stg.$$"  || true
  printf '%s' "$FP_SQL" | prod_sql | tr -d '\r' | grep -E '^[0-9]+' | sort > "${TMPDIR:-/tmp}/yg-fp-prod.$$" || true

  MISMATCH="$(comm -23 "${TMPDIR:-/tmp}/yg-fp-stg.$$" "${TMPDIR:-/tmp}/yg-fp-prod.$$" | awk '{print $1}' | paste -sd' ' -)"
  rm -f "${TMPDIR:-/tmp}/yg-fp-stg.$$" "${TMPDIR:-/tmp}/yg-fp-prod.$$"

  if [[ -z "${MISMATCH// /}" ]]; then
    ok "all ${CHANGED_N} changed item(s) match production exactly"
  else
    warn "these item(s) differ between staging and production: ${MISMATCH}"
    FAILED=$((FAILED+1))
  fi
fi

[[ "$FAILED" -gt 0 ]] && die "Published, but ${FAILED} check(s) failed. Undo with: rollback-content.sh"

prod "cd '${PROD_ROOT}' && wp option update yg_last_push \"\$(date -u '+%Y-%m-%d %H:%M:%S')\" --autoload=no --skip-plugins --skip-themes" >/dev/null 2>&1 \
  && ok "watermark recorded"
prod "rm -f /home/${PROD_USER}/_yg_remote_apply.sh '${STATE}'" || true

# ---------------------------------------------------------------------------
# 8. Durable copy of the pre-publish state, off the critical path.
#
# The in-database snapshot is the rollback path and is already in place. This
# writes the same six tables to disk so history survives a database loss, but
# detached — the publish has already finished by the time it runs, and it dumps
# the snapshot tables, which nothing else touches, so it cannot contend with the
# import. Six tables, not 642: no other client's data is written here.
# ---------------------------------------------------------------------------
SNAP_LIST=""
for t in $CONTENT_TABLES; do SNAP_LIST="${SNAP_LIST}${SNAP_LIST:+,}${SNAP}${t}"; done
# Written to a script and launched with setsid, then the shell exits immediately.
# Backgrounding the pipeline inline still held the SSH channel open until the
# dump finished — 17 seconds of a publish spent waiting for work that was
# supposed to be detached. The redirections must be on the setsid call itself,
# or the child keeps the inherited descriptors and ssh waits on them.
ARCH="/home/${PROD_USER}/_yg_archive-${STAMP}.sh"
prod "cat > '${ARCH}' <<'EOS'
#!/bin/sh
mkdir -p '${BDIR}' && chmod 700 '${BDIR}'
cd '${PROD_ROOT}' || exit 1
wp db export - --tables='${SNAP_LIST}' --single-transaction --quick --skip-plugins --skip-themes \
  | gzip -6 > '${BDIR}/content-before.sql.gz'
rm -f '${ARCH}'
EOS
chmod +x '${ARCH}'
setsid '${ARCH}' </dev/null >/dev/null 2>&1 &
exit 0" >/dev/null 2>&1 \
  && ok "archiving pre-publish content to ${BDIR}/content-before.sql.gz (detached)" \
  || warn "could not start the background archive — the in-database snapshot is still in place"

log "Published. Undo with: deployment/rollback-content.sh"
