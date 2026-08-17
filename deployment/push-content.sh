#!/usr/bin/env bash
# Push CONTENT from staging to production.
#
# This is the piece that makes "work on staging, approve, go live" real: blog
# posts, pages, Elementor designs, menus, categories and media all move.
#
# It works on one assumption, and breaks badly without it:
#
#     STAGING IS THE ONLY PLACE ANYONE EDITS CONTENT.
#
# The content tables are REPLACED, not merged. If someone writes a post directly
# on production, the next push deletes it — silently, because there is no way to
# tell "written on production" from "deleted on staging". The timestamp guard
# below catches the common case, but the rule is the real protection.
#
# What is deliberately NOT pushed, and why:
#
# Plugin settings and plugin activation DO travel. The options table is pushed
# row by row, minus a deliberately short blocklist — the only values that must
# genuinely differ between two environments:
#
#   siteurl / home    production would otherwise believe it is staging
#   *_api_key, *oauth, *credential, *license, *_token
#                     domain-scoped; Google Site Kit in particular breaks
#   cron, transients  environment-local scheduling and caches
#
# Still preserved on production, because staging cannot have them:
#
#   users          staging's are scrambled by harden-staging.sh
#   comments       written by real visitors on the live site
#   job apps       real applications, submitted to production
#   *_logs         production's own request history
#
# Usage: push-content.sh [--dry-run]

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

DRY_RUN=false
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=true

# ---------------------------------------------------------------------------
# Connection details for both ends. Content flows staging -> production only.
# ---------------------------------------------------------------------------
STG_HOST="${STAGING_HOST:-100.66.61.11}"
STG_USER="${STAGING_USER:-root}"
STG_DIR="${STAGING_STACK_DIR:-/opt/yesgermany/staging}"
STG_ROOT="${STG_DIR}/wordpress"

require_env PROD_HOST
require_env PROD_USER
PROD_ROOT="${PROD_ROOT:?PROD_ROOT is required}"
PROD_URL="${PROD_URL:-https://www.yesgermany.com}"
STG_URL="${STG_URL:-https://yg-staging.stealthlearn.in}"
PREFIX="${TABLE_PREFIX:-wpb9_}"

# The two ends are different machines with different credentials: staging is the
# OVH Docker host, production is the client's cPanel account. They must not
# share a key — the deploy key for one should never grant access to the other.
STG_KEY="${STAGING_SSH_KEY_FILE:-${HOME}/.ssh/deploy_key}"
PROD_KEY="${PROD_SSH_KEY_FILE:-${HOME}/.ssh/prod_deploy_key}"

SSH_BASE="-o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=20 -o ServerAliveInterval=20"

# Staging is reached on a non-standard port: Tailscale SSH intercepts tailnet
# port 22 and ignores SSH keys, which a CI runner can never satisfy.
STG_PORT="${STAGING_PORT:-2222}"
PROD_PORT="${PROD_PORT:-22}"

# Streaming variants — stdin is passed through, used for imports and tar pipes.
ssh_stg() {
  # shellcheck disable=SC2086
  ssh -i "$STG_KEY" -p "$STG_PORT" $SSH_BASE "${STG_USER}@${STG_HOST}" "$@"
}
ssh_prod() {
  # shellcheck disable=SC2086
  ssh -i "$PROD_KEY" -p "$PROD_PORT" $SSH_BASE "${PROD_USER}@${PROD_HOST}" "$@"
}

# Query variants — MUST use -n.
#
# Without it, ssh reads from the script's stdin. Inside "$( ... )" that silently
# swallows the input and returns an empty string, which made the "has production
# been edited?" guard pass by doing nothing at all.
ssh_stg_q() {
  # shellcheck disable=SC2086
  ssh -n -i "$STG_KEY" -p "$STG_PORT" $SSH_BASE "${STG_USER}@${STG_HOST}" "$@"
}
ssh_prod_q() {
  # shellcheck disable=SC2086
  ssh -n -i "$PROD_KEY" -p "$PROD_PORT" $SSH_BASE "${PROD_USER}@${PROD_HOST}" "$@"
}

[[ -f "$STG_KEY"  ]] || die "Staging SSH key not found at ${STG_KEY}"
[[ -f "$PROD_KEY" ]] || die "Production SSH key not found at ${PROD_KEY}"

# ---------------------------------------------------------------------------
# Tables that carry content, and are safe to replace wholesale.
# ---------------------------------------------------------------------------
CONTENT_TABLES=(
  posts postmeta
  terms termmeta term_taxonomy term_relationships
)

# Plugins deliberately switched off on staging for safety. Their production
# state must survive the push — otherwise this turns off the client's live
# analytics and ad tracking. Keep in sync with harden-staging.sh.
STAGING_DISABLED_PLUGINS=(
  google-site-kit
  google-analytics-for-wordpress
  microsoft-advertising-universal-event-tracking-uet
  aioseo-index-now
  optinmonster
  broken-link-checker-seo
  litespeed-cache
  insert-headers-and-footers
  wp-headers-and-footers
)

log "Content push: staging → production"
$DRY_RUN && warn "DRY RUN — nothing will be written to production"

# ---------------------------------------------------------------------------
# 1. Confirm both ends are what we think they are.
# ---------------------------------------------------------------------------
STG_ENV="$(ssh_stg_q "grep -oE \"WP_ENVIRONMENT_TYPE'[^)]*\" '${STG_ROOT}/wp-config.php' | grep -oE \"'(staging|production|development)'\" | tr -d \"'\" | head -1" | tr -dc 'a-z')"
[[ "$STG_ENV" == "staging" ]] || die "Source is not staging (reports '${STG_ENV}'). Refusing."
ok "source confirmed: staging"

PROD_ENV="$(ssh_prod_q "cd '${PROD_ROOT}' && wp config get WP_ENVIRONMENT_TYPE --skip-plugins --skip-themes 2>/dev/null || echo production" | tr -dc 'a-z')"
[[ "$PROD_ENV" == "production" || -z "$PROD_ENV" ]] || die "Target reports '${PROD_ENV}', expected production. Refusing."
ok "target confirmed: production"

# ---------------------------------------------------------------------------
# 2. Guard: has anyone edited production since the last push?
#
# If production has content newer than staging's newest, someone broke the
# rule. Pushing would delete their work, so stop and make a human decide.
# ---------------------------------------------------------------------------
log "Checking whether production has newer content than staging"
PROD_NEWEST="$(ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"SELECT COALESCE(MAX(post_modified),'1970-01-01') FROM ${PREFIX}posts WHERE post_type NOT IN ('revision','awsm_job_application') AND post_status IN ('publish','draft','pending','private');\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}' | head -1)"
STG_NEWEST="$(ssh_stg_q "cd '${STG_DIR}' && set -a && . ./.env && set +a && \
  docker compose exec -T db mysql -u root -p\"\$DB_ROOT_PASSWORD\" -N \"\$DB_NAME\" -e \
  \"SELECT COALESCE(MAX(post_modified),'1970-01-01') FROM ${PREFIX}posts WHERE post_type NOT IN ('revision','awsm_job_application') AND post_status IN ('publish','draft','pending','private');\" 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}' | head -1)"

log "  production newest: ${PROD_NEWEST}"
log "  staging newest:    ${STG_NEWEST}"

if [[ -n "$PROD_NEWEST" && -n "$STG_NEWEST" ]]; then
  if [[ "$PROD_NEWEST" > "$STG_NEWEST" ]]; then
    if [[ "${FORCE_PUSH:-false}" == "true" ]]; then
      warn "Production has NEWER content than staging — proceeding because FORCE_PUSH=true."
      warn "That content will be destroyed."
    else
      die "Production has content newer than staging (${PROD_NEWEST} > ${STG_NEWEST}).
   Someone edited production directly. Pushing now would delete their work.
   Bring the change into staging first, or set FORCE_PUSH=true to discard it."
    fi
  else
    ok "staging is up to date with production"
  fi
fi

$DRY_RUN && { log "Dry run complete — checks passed."; exit 0; }

# ---------------------------------------------------------------------------
# 3. Back up production before touching anything.
# ---------------------------------------------------------------------------
STAMP="$(date -u +%Y-%m-%d-%H%M%S)"
BACKUP_DIR="${PROD_BACKUP_DIR:?PROD_BACKUP_DIR is required}/${STAMP}"
log "Backing up production → ${BACKUP_DIR}"
ssh_prod "mkdir -p '${BACKUP_DIR}' && chmod 700 '${BACKUP_DIR}'"
ssh_prod "cd '${PROD_ROOT}' && wp db export - --single-transaction --quick --skip-plugins --skip-themes | gzip -6 > '${BACKUP_DIR}/database.sql.gz'" \
  || die "Backup failed — aborting before any change."
ssh_prod "gzip -t '${BACKUP_DIR}/database.sql.gz'" || die "Backup is corrupt — aborting."
ok "database backed up ($(ssh_prod_q "du -h '${BACKUP_DIR}/database.sql.gz' | cut -f1"))"

# ---------------------------------------------------------------------------
# 4. Record production state that must survive the push.
# ---------------------------------------------------------------------------
log "Recording production state to preserve"
ACTIVE_PLUGINS_BEFORE="$(ssh_prod_q "cd '${PROD_ROOT}' && wp option get active_plugins --format=json --skip-plugins --skip-themes 2>/dev/null" | tr -d '\r')"
ssh_prod "cd '${PROD_ROOT}' && wp db export '${BACKUP_DIR}/preserved.sql' \
   --tables=${PREFIX}users,${PREFIX}usermeta,${PREFIX}comments,${PREFIX}commentmeta \
   --skip-plugins --skip-themes" >/dev/null 2>&1 \
  && ok "users and comments snapshotted"

# Job applications live INSIDE the posts table, which step 6 replaces. Copy them
# into holding tables first so they can be put back afterwards, otherwise the
# push silently destroys real applications.
COMMENTS_BEFORE="$(ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"SELECT COUNT(*) FROM ${PREFIX}comments;\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]+$' | head -1)"
USERS_BEFORE="$(ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"SELECT COUNT(*) FROM ${PREFIX}users;\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]+$' | head -1)"
log "  baseline — comments: ${COMMENTS_BEFORE}, users: ${USERS_BEFORE}"

JOBAPPS="$(ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='awsm_job_application';\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]+$' | head -1)"
log "  job applications on production: ${JOBAPPS:-0}"

# ---------------------------------------------------------------------------
# Restore-on-exit.
#
# This is the difference between "a failure loses client data" and "a failure is
# just a failure". The push replaces the posts table, which contains real job
# applications; previously they were put back at the END of the script, so a
# connection drop mid-import left production missing them silently. That
# happened, and 4 real applications had to be recovered by hand.
#
# The trap runs on ANY exit — error, kill, or success — so the protected rows go
# back regardless of how the script ends.
# ---------------------------------------------------------------------------
RESTORE_NEEDED=false

restore_protected() {
  local rc=$?
  [[ "$RESTORE_NEEDED" != "true" ]] && { rm -f "${TMP_SQL:-}" "${TMP_SQL:-}.tmp" 2>/dev/null; return $rc; }

  echo
  log "Restoring protected production data (exit code ${rc})"

  if [[ "${JOBAPPS:-0}" -gt 0 ]]; then
    ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"
      INSERT IGNORE INTO ${PREFIX}posts    SELECT * FROM ${PREFIX}_hold_jobapps;
      INSERT IGNORE INTO ${PREFIX}postmeta SELECT * FROM ${PREFIX}_hold_jobmeta;\" --skip-plugins --skip-themes" >/dev/null 2>&1 || true

    local now
    now="$(ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='awsm_job_application';\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]+$' | head -1)"

    if [[ "${now:-0}" -ge "${JOBAPPS}" ]]; then
      ok "job applications restored: ${now}/${JOBAPPS}"
      ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"
        DROP TABLE IF EXISTS ${PREFIX}_hold_jobapps;
        DROP TABLE IF EXISTS ${PREFIX}_hold_jobmeta;\" --skip-plugins --skip-themes" >/dev/null 2>&1 || true
    else
      warn "job applications NOT fully restored (${now:-0}/${JOBAPPS})."
      warn "They remain in ${PREFIX}_hold_jobapps / ${PREFIX}_hold_jobmeta — do not drop those tables."
    fi
  fi

  # Users and comments were snapshotted before the replace.
  ssh_prod_q "cd '${PROD_ROOT}' && test -f '${BACKUP_DIR}/preserved.sql' && wp db import '${BACKUP_DIR}/preserved.sql' --skip-plugins --skip-themes" >/dev/null 2>&1 \
    && ok "users and comments restored" || true

  if [[ $rc -ne 0 ]]; then
    warn "The push did not complete. Production content may be partially updated."
    warn "Full restore if needed: ${BACKUP_DIR}/database.sql.gz"
  fi

  rm -f "${TMP_SQL:-}" "${TMP_SQL:-}.tmp" 2>/dev/null
  return $rc
}
trap restore_protected EXIT

if [[ "${JOBAPPS:-0}" -gt 0 ]]; then
  ssh_prod "cd '${PROD_ROOT}' && wp db query \"
    DROP TABLE IF EXISTS ${PREFIX}_hold_jobapps;
    DROP TABLE IF EXISTS ${PREFIX}_hold_jobmeta;
    CREATE TABLE ${PREFIX}_hold_jobapps LIKE ${PREFIX}posts;
    INSERT INTO ${PREFIX}_hold_jobapps
      SELECT * FROM ${PREFIX}posts WHERE post_type='awsm_job_application';
    CREATE TABLE ${PREFIX}_hold_jobmeta LIKE ${PREFIX}postmeta;
    INSERT INTO ${PREFIX}_hold_jobmeta
      SELECT pm.* FROM ${PREFIX}postmeta pm
       INNER JOIN ${PREFIX}posts p ON p.ID = pm.post_id
       WHERE p.post_type='awsm_job_application';
  \" --skip-plugins --skip-themes" >/dev/null 2>&1 \
    && ok "${JOBAPPS} job application(s) held aside" \
    || die "Could not preserve job applications — aborting rather than risk losing them."
fi

# From here on, the posts table is about to be replaced. Arm the restore.
RESTORE_NEEDED=true

# ---------------------------------------------------------------------------
# 5. Export content tables from staging.
# ---------------------------------------------------------------------------
log "Exporting content from staging"
TBL_LIST=""
for t in "${CONTENT_TABLES[@]}"; do TBL_LIST="${TBL_LIST}${PREFIX}${t},"; done
TBL_LIST="${TBL_LIST%,}"

# Compressed on the wire. The client's host accepts ~3 MB/s of raw data, and
# SQL compresses roughly 7:1, so this turns a ~70s exposed stream into ~10s.
TMP_SQL="$(mktemp).gz"
ssh_stg_q "cd '${STG_DIR}' && set -a && . ./.env && set +a && \
         docker compose exec -T db mysqldump -u root -p\"\$DB_ROOT_PASSWORD\" \
           --single-transaction --quick --default-character-set=utf8mb4 \
           \"\$DB_NAME\" ${TBL_LIST//,/ } 2>/dev/null | gzip -6" > "$TMP_SQL" \
  || die "Could not export content from staging."
[[ -s "$TMP_SQL" ]] || die "Staging export is empty — refusing to wipe production content."
gzip -t "$TMP_SQL" || die "Staging export is corrupt — refusing to continue."
ok "exported $(du -h "$TMP_SQL" | cut -f1) compressed, integrity verified"

# Rewrite staging URLs back to production before the data lands.
log "Rewriting ${STG_URL} → ${PROD_URL}"
gzip -dc "$TMP_SQL" | sed "s|${STG_URL}|${PROD_URL}|g" | gzip -6 > "${TMP_SQL}.tmp" \
  && mv "${TMP_SQL}.tmp" "$TMP_SQL"
REMAINING="$(gzip -dc "$TMP_SQL" | grep -c "$STG_URL" || true)"
[[ "$REMAINING" == "0" ]] && ok "no staging URLs remain" || warn "${REMAINING} staging URL(s) still present"

# ---------------------------------------------------------------------------
# 6. Apply to production.
# ---------------------------------------------------------------------------
# Copy first, then import server-side. Streaming the import through a third
# machine is what failed before: a dropped connection mid-import left the posts
# table replaced but the protected rows not yet restored. A file copy can be
# retried; a pipe cannot, and nothing is at risk while the file is in transit.
log "Copying content to production"
REMOTE_SQL="${BACKUP_DIR}/incoming-content.sql.gz"
COPIED=false
for attempt in 1 2 3; do
  if scp -q -i "$PROD_KEY" -P "$PROD_PORT" -o BatchMode=yes -o StrictHostKeyChecking=yes \
       "$TMP_SQL" "${PROD_USER}@${PROD_HOST}:${REMOTE_SQL}"; then
    if ssh_prod_q "gzip -t '${REMOTE_SQL}'"; then
      COPIED=true; ok "copied and verified (attempt ${attempt})"; break
    fi
    warn "copy arrived corrupt, retrying"
  else
    warn "copy attempt ${attempt} failed, retrying"
  fi
  sleep 5
done
$COPIED || die "Could not copy content to production. Nothing was changed."

log "Importing on production (server-side, no network in the loop)"
ssh_prod "cd '${PROD_ROOT}' && gzip -dc '${REMOTE_SQL}' | wp db query" \
  || die "Import failed. Restore with: ${BACKUP_DIR}/database.sql.gz"
ssh_prod_q "rm -f '${REMOTE_SQL}'" || true
ok "content applied"

# ---------------------------------------------------------------------------
# 7. Put back everything that must not come from staging.
# ---------------------------------------------------------------------------
log "Restoring preserved production data"
ssh_prod "cd '${PROD_ROOT}' && wp db import '${BACKUP_DIR}/preserved.sql' --skip-plugins --skip-themes" >/dev/null 2>&1 \
  && ok "users and comments restored"

# Job applications and users/comments are restored by the trap (restore_protected),
# so they come back even if this script dies before reaching the end.

# ---------------------------------------------------------------------------
# 7b. Plugin settings and plugin activation.
#
# The whole options table travels, minus the short blocklist above. That is what
# lets a plugin be installed and configured on staging and simply work on live.
# ---------------------------------------------------------------------------
log "Pushing plugin settings"

OPT_SQL="$(mktemp)"
ssh_stg "cd '${STG_DIR}' && set -a && . ./.env && set +a && \
         docker compose exec -T db mysqldump -u root -p\"\$DB_ROOT_PASSWORD\" \
           --single-transaction --quick --default-character-set=utf8mb4 \
           --no-create-info --skip-add-locks --complete-insert \
           \"\$DB_NAME\" ${PREFIX}options 2>/dev/null" > "$OPT_SQL" \
  || die "Could not export options from staging."
[[ -s "$OPT_SQL" ]] || die "Options export is empty — refusing to continue."
sed -i "s|${STG_URL}|${PROD_URL}|g" "$OPT_SQL"

# Load staging's options into a holding table, then copy across everything
# except the values that must stay environment-specific.
ssh_prod "cd '${PROD_ROOT}' && wp db query \"
  DROP TABLE IF EXISTS ${PREFIX}_incoming_options;
  CREATE TABLE ${PREFIX}_incoming_options LIKE ${PREFIX}options;\" --skip-plugins --skip-themes" >/dev/null 2>&1

sed "s/INSERT INTO \`${PREFIX}options\`/INSERT INTO \`${PREFIX}_incoming_options\`/g" "$OPT_SQL" \
  | ssh_prod "cd '${PROD_ROOT}' && wp db query" \
  || die "Could not stage incoming options on production."
rm -f "$OPT_SQL"

# The blocklist. Everything not matched here is taken from staging.
OPT_BLOCK="option_name IN ('siteurl','home','cron','active_plugins','recently_activated','_transient_doing_cron')
  OR option_name LIKE '\_transient%' OR option_name LIKE '\_site\_transient%'
  OR option_name LIKE '%api\_key%'   OR option_name LIKE '%apikey%'
  OR option_name LIKE '%oauth%'      OR option_name LIKE '%credential%'
  OR option_name LIKE '%license%'    OR option_name LIKE '%\_token%'
  OR option_name LIKE 'googlesitekit%'"

ssh_prod "cd '${PROD_ROOT}' && wp db query \"
  -- update settings that already exist on production
  UPDATE ${PREFIX}options o
    INNER JOIN ${PREFIX}_incoming_options i ON i.option_name = o.option_name
     SET o.option_value = i.option_value, o.autoload = i.autoload
   WHERE NOT (${OPT_BLOCK});
  -- add settings that are new (a newly installed plugin)
  INSERT INTO ${PREFIX}options (option_name, option_value, autoload)
    SELECT i.option_name, i.option_value, i.autoload
      FROM ${PREFIX}_incoming_options i
      LEFT JOIN ${PREFIX}options o ON o.option_name = i.option_name
     WHERE o.option_id IS NULL AND NOT (${OPT_BLOCK});
  \" --skip-plugins --skip-themes" >/dev/null 2>&1 \
  && ok "plugin settings pushed" || warn "settings push had problems"

# ---------------------------------------------------------------------------
# Plugin activation: take staging's list, then force back on the plugins that
# are only disabled on staging for safety. A plugin newly activated on staging
# therefore goes live; the client's analytics and ad tracking stay on.
# ---------------------------------------------------------------------------
log "Reconciling active plugins"
STG_ACTIVE="$(ssh_stg_q "cd '${STG_DIR}' && docker compose run --rm -T wpcli wp option get active_plugins --format=json --path=/var/www/html --skip-plugins --skip-themes 2>/dev/null" | tr -d '\r' | grep -E '^\[' | head -1)"

if [[ -n "$STG_ACTIVE" ]]; then
  ssh_prod "cd '${PROD_ROOT}' && wp option update active_plugins '${STG_ACTIVE}' --format=json --skip-plugins --skip-themes" >/dev/null 2>&1 \
    && ok "activation state taken from staging"

  for p in "${STAGING_DISABLED_PLUGINS[@]}"; do
    ssh_prod "cd '${PROD_ROOT}' && wp plugin activate '${p}' --skip-plugins --skip-themes" >/dev/null 2>&1 \
      && ok "re-enabled on production: ${p}" \
      || warn "could not re-enable ${p} — check it manually"
  done
else
  warn "could not read staging's plugin list; leaving production activation unchanged"
  [[ -n "$ACTIVE_PLUGINS_BEFORE" ]] && ssh_prod "cd '${PROD_ROOT}' && wp option update active_plugins '${ACTIVE_PLUGINS_BEFORE}' --format=json --skip-plugins --skip-themes" >/dev/null 2>&1
fi

ssh_prod "cd '${PROD_ROOT}' && wp db query \"DROP TABLE IF EXISTS ${PREFIX}_incoming_options;\" --skip-plugins --skip-themes" >/dev/null 2>&1

# Guard: production must never end up pointing at the staging hostname.
LIVE_URL="$(ssh_prod_q "cd '${PROD_ROOT}' && wp option get siteurl --skip-plugins --skip-themes 2>/dev/null" | tr -d '\r' | grep -E '^https?://' | head -1)"
if [[ "$LIVE_URL" == *"stealthlearn"* ]]; then
  die "production siteurl is now '${LIVE_URL}' — restoring from ${BACKUP_DIR}/database.sql.gz is required IMMEDIATELY."
fi
ok "production siteurl intact: ${LIVE_URL}"

# ---------------------------------------------------------------------------
# 8. Media
# ---------------------------------------------------------------------------
log "Syncing new media (staging → production, additive only)"
ssh_stg "cd '${STG_ROOT}/wp-content' && tar czf - uploads 2>/dev/null" \
  | ssh_prod "cd '${PROD_ROOT}/wp-content' && tar xzf - --skip-old-files 2>/dev/null" \
  && ok "media synced (existing files left untouched)" || warn "media sync had problems"

# ---------------------------------------------------------------------------
# 9. Flush caches so the new content is actually served.
# ---------------------------------------------------------------------------
ssh_prod "cd '${PROD_ROOT}' && wp cache flush --skip-plugins --skip-themes" >/dev/null 2>&1 && ok "cache flushed"
ssh_prod "cd '${PROD_ROOT}' && wp litespeed-purge all --skip-themes" >/dev/null 2>&1 && ok "LiteSpeed purged" || true
ssh_prod "cd '${PROD_ROOT}' && wp elementor flush-css --skip-themes" >/dev/null 2>&1 && ok "Elementor CSS regenerated" || true

# ---------------------------------------------------------------------------
# 10. Verify. A push that quietly loses data is worse than one that fails.
# ---------------------------------------------------------------------------
log "Verifying production"
FAILED=0
check() { # name expected actual
  if [[ "$2" == "$3" ]]; then ok "$1: $3"; else warn "$1: expected $2, found $3"; FAILED=$((FAILED+1)); fi
}
PQ() { ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"$1\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]+$|^https?://' | head -1; }

check "job applications" "${JOBAPPS:-0}" "$(PQ "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='awsm_job_application';")"
check "comments"         "${COMMENTS_BEFORE:-?}" "$(PQ "SELECT COUNT(*) FROM ${PREFIX}comments;")"
check "users"            "${USERS_BEFORE:-?}"    "$(PQ "SELECT COUNT(*) FROM ${PREFIX}users;")"

LIVE_CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 60 "${PROD_URL}/" || echo 000)"
[[ "$LIVE_CODE" == "200" ]] && ok "live site HTTP 200" || { warn "live site returned HTTP ${LIVE_CODE}"; FAILED=$((FAILED+1)); }

if [[ "$FAILED" -gt 0 ]]; then
  die "Push finished but ${FAILED} check(s) failed. Backup: ${BACKUP_DIR}/database.sql.gz"
fi

log "Content push complete."
log "Rollback if needed: ${BACKUP_DIR}/database.sql.gz"
