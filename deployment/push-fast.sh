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

stg()  { ssh -n -i "$STG_KEY"  -p "$STG_PORT" $SSHB "${STG_USER}@${STG_HOST}"  "$@"; }
prod() { ssh -n -i "$PROD_KEY" $SSHB "${PROD_USER}@${PROD_HOST}" "$@"; }

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
PROD_NEWEST="$(prod "cd '${PROD_ROOT}' && wp db query \"SELECT MAX(post_modified) FROM ${PREFIX}posts WHERE post_type NOT IN ('revision','awsm_job_application') AND post_status NOT IN ('auto-draft','inherit','trash');\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]{4}-' | head -1)"

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

$DRY_RUN && { log "Dry run complete."; exit 0; }

# ---------------------------------------------------------------------------
# 3. Back up production
# ---------------------------------------------------------------------------
BDIR="${PROD_BACKUP_DIR}/${STAMP}"
log "Backing up production"
prod "mkdir -p '${BDIR}' && chmod 700 '${BDIR}' && cd '${PROD_ROOT}' && \
      wp db export - --single-transaction --quick --skip-plugins --skip-themes | gzip -6 > '${BDIR}/database.sql.gz'" \
  || die "Backup failed — nothing was changed."
prod "gzip -t '${BDIR}/database.sql.gz'" || die "Backup is corrupt — nothing was changed."
ok "backed up ($(prod "du -h '${BDIR}/database.sql.gz' | cut -f1"))"

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
for t in posts postmeta terms termmeta term_taxonomy term_relationships; do TBLS="${TBLS} ${PREFIX}${t}"; done
STG_FILE="/tmp/yg-content-${STAMP}.sql.gz"

log "Dumping content on staging"
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
log "Applying on production (detached — safe to disconnect)"
scp -q -i "$PROD_KEY" $SSHB "${SCRIPT_DIR}/remote-apply.sh" "${PROD_USER}@${PROD_HOST}:/home/${PROD_USER}/_yg_remote_apply.sh"
prod "chmod +x /home/${PROD_USER}/_yg_remote_apply.sh && : > '${STATE}' && \
      WP_ROOT='${PROD_ROOT}' setsid nohup /home/${PROD_USER}/_yg_remote_apply.sh \
        '${REMOTE_SQL}' '${PREFIX}' '${STATE}' >/dev/null 2>&1 < /dev/null &" || true

for i in $(seq 1 120); do
  sleep 5
  S="$(prod "tail -1 '${STATE}' 2>/dev/null" | tr -d '\r')"
  case "$S" in
    DONE)   ok "applied"; break ;;
    FAILED) prod "cat '${STATE}'" | sed 's/^/    /'; die "Apply failed on production. Backup: ${BDIR}/database.sql.gz" ;;
    *)      [[ -n "$S" ]] && printf '\r    %-60s' "$S" ;;
  esac
  [[ $i -eq 120 ]] && die "Timed out waiting for production. Check ${STATE} on the server."
done
echo

# ---------------------------------------------------------------------------
# 7. Verify, then record the watermark
# ---------------------------------------------------------------------------
log "Verifying"
FAILED=0
PN() { prod "cd '${PROD_ROOT}' && wp db query \"$1\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]+$' | head -1; }
for pair in "job applications:${PREFIX}posts WHERE post_type='awsm_job_application'" \
            "comments:${PREFIX}comments" "users:${PREFIX}users"; do
  name="${pair%%:*}"; expr="${pair#*:}"
  v="$(PN "SELECT COUNT(*) FROM ${expr};")"
  [[ "${v:-0}" -gt 0 ]] && ok "${name}: ${v}" || { warn "${name}: ${v:-0}"; FAILED=$((FAILED+1)); }
done

LIVE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 60 "${PROD_URL}/" || echo 000)"
[[ "$LIVE" == "200" ]] && ok "live site HTTP 200" || { warn "live site HTTP ${LIVE}"; FAILED=$((FAILED+1)); }

SURL="$(prod "cd '${PROD_ROOT}' && wp option get siteurl --skip-plugins --skip-themes 2>/dev/null" | tr -d '\r' | grep -E '^https?' | head -1)"
[[ "$SURL" == *stealthlearn* ]] && die "production siteurl is '${SURL}' — RESTORE NOW from ${BDIR}/database.sql.gz"
ok "siteurl intact: ${SURL}"

[[ "$FAILED" -gt 0 ]] && die "Published, but ${FAILED} check(s) failed. Backup: ${BDIR}/database.sql.gz"

prod "cd '${PROD_ROOT}' && wp option update yg_last_push \"\$(date -u '+%Y-%m-%d %H:%M:%S')\" --autoload=no --skip-plugins --skip-themes" >/dev/null 2>&1 \
  && ok "watermark recorded"
prod "rm -f /home/${PROD_USER}/_yg_remote_apply.sh '${STATE}'" || true

log "Published. Backup kept at ${BDIR}/database.sql.gz"
