#!/usr/bin/env bash
# Undo the last content publish on production, from the in-database snapshot
# push-fast.sh takes immediately before it writes.
#
# This restores the six tables the publish replaces — posts, postmeta, terms,
# termmeta, term_taxonomy, term_relationships — and nothing else. It does not
# touch options, users, plugins or any of the 464 tables belonging to the other
# client sites that share this database.
#
# Anything real that arrived AFTER the publish is preserved, not rolled back:
# job applications, comments and users are copied aside first and put back
# afterwards. Rolling those away would destroy live leads, which is a far worse
# outcome than a wrong headline.
#
# Usage:
#   rollback-content.sh --check     show what a rollback would restore
#   rollback-content.sh --yes       do it

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

MODE="${1:---check}"
case "$MODE" in
  --check|--yes) ;;
  *) die "Usage: rollback-content.sh [--check|--yes]" ;;
esac

PROD_HOST="${PROD_HOST:?PROD_HOST required}"
PROD_USER="${PROD_USER:?PROD_USER required}"
PROD_ROOT="${PROD_ROOT:?PROD_ROOT required}"
PREFIX="${TABLE_PREFIX:-wpb9_}"
PROD_KEY="${PROD_SSH_KEY_FILE:-${HOME}/.ssh/prod_deploy_key}"
# AddressFamily=inet: production's hostname carries an AAAA record, and GitHub
# runners have no IPv6 route. Without this, ssh picks the v6 address and dies
# with "Network is unreachable" — which is how the 2026-09-01 health check failed
# after a publish that had already succeeded.
SSHB="-o AddressFamily=inet -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=20 -o ServerAliveInterval=15"

CONTENT_TABLES="posts postmeta terms termmeta term_taxonomy term_relationships"
SNAP="${PREFIX}ygsnap_"

prod()     { ssh -n -i "$PROD_KEY" $SSHB "${PROD_USER}@${PROD_HOST}" "$@"; }
prod_sql() { ssh    -i "$PROD_KEY" $SSHB "${PROD_USER}@${PROD_HOST}" \
               "cd '${PROD_ROOT}' && wp db query --skip-column-names --skip-plugins --skip-themes" 2>/dev/null; }

[[ -f "$PROD_KEY" ]] || die "production key missing: ${PROD_KEY}"

# ---------------------------------------------------------------------------
# Is there a snapshot, and is it complete?
# ---------------------------------------------------------------------------
log "Checking the snapshot on production"

MISSING=""
for t in $CONTENT_TABLES; do
  n="$(printf "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='%s%s';\n" \
        "$SNAP" "$t" | prod_sql | tr -d '\r' | grep -E '^[0-9]+$' | head -1)"
  [[ "${n:-0}" -eq 1 ]] || MISSING="${MISSING} ${SNAP}${t}"
done
[[ -z "${MISSING// /}" ]] || die "No usable snapshot — missing:${MISSING}
   Nothing was changed. Restore from a dated archive under the backup directory instead."

COUNTS="$(for t in $CONTENT_TABLES; do
    printf "SELECT CONCAT('%s:',(SELECT COUNT(*) FROM %s%s),':',(SELECT COUNT(*) FROM %s%s));\n" \
      "$t" "$PREFIX" "$t" "$SNAP" "$t"
  done | prod_sql | tr -d '\r' | grep ':')"

log "  table                     live      snapshot"
while IFS=: read -r t live snapped; do
  [[ -n "$t" ]] || continue
  printf '      %-22s %8s  %12s\n' "$t" "${live:-?}" "${snapped:-?}"
done <<< "$COUNTS"

WATERMARK="$(prod "cd '${PROD_ROOT}' && wp option get yg_last_push --skip-plugins --skip-themes 2>/dev/null" \
  | tr -d '\r' | grep -E '^[0-9]{4}-' | head -1 || true)"
log "  snapshot predates the publish recorded at ${WATERMARK:-unknown}"

if [[ "$MODE" == "--check" ]]; then
  log "Check only. Re-run with --yes to restore."
  exit 0
fi

# ---------------------------------------------------------------------------
# Do it on the server, detached, with its own restore-on-exit.
#
# Same reasoning as remote-apply.sh: a rollback driven over SSH stops half-done
# if the connection drops, and a half-done rollback is worse than the state it
# was fixing.
# ---------------------------------------------------------------------------
STAMP="$(date -u +%Y%m%d-%H%M%S)"
STATE="/home/${PROD_USER}/_yg_rollback-${STAMP}.state"
REMOTE="/home/${PROD_USER}/_yg_rollback-${STAMP}.sh"

warn "Restoring production content from the snapshot."

cat > "/tmp/yg-rollback-${STAMP}.sh" <<ROLLBACK
#!/usr/bin/env bash
set -Eeuo pipefail
PREFIX='${PREFIX}'
SNAP='${SNAP}'
STATE='${STATE}'
cd "\${WP_ROOT:-\$HOME/public_html}" || { echo FAILED >> "\$STATE"; exit 1; }

say() { printf '%s %s\n' "\$(date -u +%H:%M:%S)" "\$*" >> "\$STATE"; }
# Every wp db query is its own session, so the relaxed sql_mode has to travel
# with each statement rather than being set once: WordPress columns default to
# '0000-00-00 00:00:00' and CREATE TABLE ... LIKE fails under strict mode.
q()   { wp db query "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'; \$1" --skip-plugins --skip-themes 2>/dev/null; }
n()   { wp db query "\$1" --skip-column-names --skip-plugins --skip-themes 2>/dev/null | tr -d '\r' | grep -E '^[0-9]+\$' | head -1; }

say "START"
JOBAPPS="\$(n "SELECT COUNT(*) FROM \${PREFIX}posts WHERE post_type='awsm_job_application';")"
COMMENTS="\$(n "SELECT COUNT(*) FROM \${PREFIX}comments;")"
USERS="\$(n "SELECT COUNT(*) FROM \${PREFIX}users;")"
say "baseline jobapps=\${JOBAPPS:-0} comments=\${COMMENTS:-0} users=\${USERS:-0}"

ARMED=false
finish() {
  local rc=\$?
  if [[ "\$ARMED" == "true" ]]; then
    q "INSERT IGNORE INTO \${PREFIX}posts    SELECT * FROM \${PREFIX}_hold_jobapps;
       INSERT IGNORE INTO \${PREFIX}postmeta SELECT * FROM \${PREFIX}_hold_jobmeta;" || true
    ja="\$(n "SELECT COUNT(*) FROM \${PREFIX}posts WHERE post_type='awsm_job_application';")"
    say "after restore jobapps=\${ja:-0}/\${JOBAPPS:-0}"
    if [[ "\${ja:-0}" -ge "\${JOBAPPS:-0}" ]]; then
      q "DROP TABLE IF EXISTS \${PREFIX}_hold_jobapps; DROP TABLE IF EXISTS \${PREFIX}_hold_jobmeta;" || true
      say "job applications verified, holds dropped"
    else
      say "WARNING job applications incomplete — holds KEPT for recovery"
      rc=1
    fi
  fi
  [[ \$rc -eq 0 ]] && echo "DONE" >> "\$STATE" || echo "FAILED" >> "\$STATE"
  exit \$rc
}
trap finish EXIT

# Leads that arrived after the publish live in the posts table, so the snapshot
# does not contain them. Hold them aside before overwriting it.
say "holding job applications"
q "DROP TABLE IF EXISTS \${PREFIX}_hold_jobapps; CREATE TABLE \${PREFIX}_hold_jobapps LIKE \${PREFIX}posts;
   DROP TABLE IF EXISTS \${PREFIX}_hold_jobmeta; CREATE TABLE \${PREFIX}_hold_jobmeta LIKE \${PREFIX}postmeta;
   INSERT INTO \${PREFIX}_hold_jobapps SELECT * FROM \${PREFIX}posts WHERE post_type='awsm_job_application';
   INSERT INTO \${PREFIX}_hold_jobmeta SELECT pm.* FROM \${PREFIX}postmeta pm
     INNER JOIN \${PREFIX}posts p ON p.ID=pm.post_id WHERE p.post_type='awsm_job_application';" \
  || { say "FATAL could not hold job applications"; exit 1; }

HELD="\$(n "SELECT COUNT(*) FROM \${PREFIX}_hold_jobapps;")"
[[ "\${HELD:-0}" -eq "\${JOBAPPS:-0}" ]] || { say "FATAL held \${HELD:-0} of \${JOBAPPS:-0}"; exit 1; }
ARMED=true
say "held ok"

# Swap each table with its snapshot. RENAME is atomic per statement, so a table
# is never missing — readers see either the old or the new one.
for t in ${CONTENT_TABLES}; do
  say "restoring \$t"
  q "DROP TABLE IF EXISTS \${PREFIX}ygold_\${t};
     RENAME TABLE \${PREFIX}\${t} TO \${PREFIX}ygold_\${t}, \${SNAP}\${t} TO \${PREFIX}\${t};
     CREATE TABLE \${SNAP}\${t} LIKE \${PREFIX}\${t};
     INSERT INTO \${SNAP}\${t} SELECT * FROM \${PREFIX}\${t};
     DROP TABLE IF EXISTS \${PREFIX}ygold_\${t};" || { say "FATAL restoring \$t"; exit 1; }
done

wp cache flush --skip-plugins --skip-themes >/dev/null 2>&1 || true
wp litespeed-purge all --skip-themes >/dev/null 2>&1 || true
wp elementor flush-css --skip-themes >/dev/null 2>&1 || true
say "caches flushed"
say "SUCCESS"
ROLLBACK

scp -q -i "$PROD_KEY" $SSHB "/tmp/yg-rollback-${STAMP}.sh" "${PROD_USER}@${PROD_HOST}:${REMOTE}"
rm -f "/tmp/yg-rollback-${STAMP}.sh"
prod "chmod +x '${REMOTE}' && setsid nohup '${REMOTE}' >/dev/null 2>&1 < /dev/null & echo started" >/dev/null

log "Restoring (detached — safe to disconnect)"
for _ in $(seq 1 60); do
  S="$(prod "tail -1 '${STATE}' 2>/dev/null" | tr -d '\r' || true)"
  case "$S" in
    *DONE*)   ok "content restored"; break ;;
    *FAILED*) prod "cat '${STATE}'" | sed 's/^/    /'; die "Rollback failed. Holding tables kept on production." ;;
    *) [[ -n "$S" ]] && printf '      %s\n' "$S" ;;
  esac
  sleep 5
done

prod "rm -f '${REMOTE}' '${STATE}'" || true
ok "Rolled back. The snapshot now holds the restored state."
