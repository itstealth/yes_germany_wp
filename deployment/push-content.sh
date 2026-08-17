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
#   options        Holds siteurl, API keys, OAuth tokens and which plugins are
#                  active. Copying it makes production believe it is staging.
#                  Settings changes go through migrations/ instead.
#   users          Staging's are scrambled; pushing them locks everyone out.
#   comments       Written by real visitors on production.
#   job apps       awsm_job_application posts are real applications.
#   *_logs         Production's own request and activity history.
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

# Streaming variants — stdin is passed through, used for imports and tar pipes.
ssh_stg() {
  # shellcheck disable=SC2086
  ssh -i "$STG_KEY" $SSH_BASE "${STG_USER}@${STG_HOST}" "$@"
}
ssh_prod() {
  # shellcheck disable=SC2086
  ssh -i "$PROD_KEY" $SSH_BASE "${PROD_USER}@${PROD_HOST}" "$@"
}

# Query variants — MUST use -n.
#
# Without it, ssh reads from the script's stdin. Inside "$( ... )" that silently
# swallows the input and returns an empty string, which made the "has production
# been edited?" guard pass by doing nothing at all.
ssh_stg_q() {
  # shellcheck disable=SC2086
  ssh -n -i "$STG_KEY" $SSH_BASE "${STG_USER}@${STG_HOST}" "$@"
}
ssh_prod_q() {
  # shellcheck disable=SC2086
  ssh -n -i "$PROD_KEY" $SSH_BASE "${PROD_USER}@${PROD_HOST}" "$@"
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
PROD_NEWEST="$(ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"SELECT COALESCE(MAX(post_modified_gmt),'1970-01-01') FROM ${PREFIX}posts WHERE post_type NOT IN ('revision','awsm_job_application') AND post_status IN ('publish','draft','pending','private');\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}' | head -1)"
STG_NEWEST="$(ssh_stg_q "cd '${STG_DIR}' && set -a && . ./.env && set +a && \
  docker compose exec -T db mysql -u root -p\"\$DB_ROOT_PASSWORD\" -N \"\$DB_NAME\" -e \
  \"SELECT COALESCE(MAX(post_modified_gmt),'1970-01-01') FROM ${PREFIX}posts WHERE post_type NOT IN ('revision','awsm_job_application') AND post_status IN ('publish','draft','pending','private');\" 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}' | head -1)"

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
JOBAPPS="$(ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='awsm_job_application';\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]+$' | head -1)"
log "  job applications on production: ${JOBAPPS:-0}"

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

# ---------------------------------------------------------------------------
# 5. Export content tables from staging.
# ---------------------------------------------------------------------------
log "Exporting content from staging"
TBL_LIST=""
for t in "${CONTENT_TABLES[@]}"; do TBL_LIST="${TBL_LIST}${PREFIX}${t},"; done
TBL_LIST="${TBL_LIST%,}"

TMP_SQL="$(mktemp)"
trap 'rm -f "$TMP_SQL"' EXIT
ssh_stg "cd '${STG_DIR}' && set -a && . ./.env && set +a && \
         docker compose exec -T db mysqldump -u root -p\"\$DB_ROOT_PASSWORD\" \
           --single-transaction --quick --default-character-set=utf8mb4 \
           \"\$DB_NAME\" ${TBL_LIST//,/ } 2>/dev/null" > "$TMP_SQL" \
  || die "Could not export content from staging."
[[ -s "$TMP_SQL" ]] || die "Staging export is empty — refusing to wipe production content."
ok "exported $(du -h "$TMP_SQL" | cut -f1) of content"

# Rewrite staging URLs back to production before the data lands.
log "Rewriting ${STG_URL} → ${PROD_URL}"
sed -i "s|${STG_URL}|${PROD_URL}|g" "$TMP_SQL"
REMAINING="$(grep -c "$STG_URL" "$TMP_SQL" || true)"
[[ "$REMAINING" == "0" ]] && ok "no staging URLs remain" || warn "${REMAINING} staging URL(s) still present"

# ---------------------------------------------------------------------------
# 6. Apply to production.
# ---------------------------------------------------------------------------
log "Applying content to production"
ssh_prod "cd '${PROD_ROOT}' && wp db query" < "$TMP_SQL" \
  || die "Import failed. Restore with: ${BACKUP_DIR}/database.sql.gz"
ok "content applied"

# ---------------------------------------------------------------------------
# 7. Put back everything that must not come from staging.
# ---------------------------------------------------------------------------
log "Restoring preserved production data"
ssh_prod "cd '${PROD_ROOT}' && wp db import '${BACKUP_DIR}/preserved.sql' --skip-plugins --skip-themes" >/dev/null 2>&1 \
  && ok "users and comments restored"

# Job applications were inside the replaced posts table; put them back.
if [[ "${JOBAPPS:-0}" -gt 0 ]]; then
  ssh_prod "cd '${PROD_ROOT}' && wp db query \"
    INSERT IGNORE INTO ${PREFIX}posts    SELECT * FROM ${PREFIX}_hold_jobapps;
    INSERT IGNORE INTO ${PREFIX}postmeta SELECT * FROM ${PREFIX}_hold_jobmeta;
  \" --skip-plugins --skip-themes" >/dev/null 2>&1 \
    && ok "${JOBAPPS} job application(s) restored" \
    || warn "Job applications NOT restored — they are still in ${PREFIX}_hold_jobapps"

  RESTORED="$(ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='awsm_job_application';\" --skip-column-names 2>/dev/null" | tr -d '\r' | grep -E '^[0-9]+$' | head -1)"
  if [[ "${RESTORED:-0}" -eq "${JOBAPPS}" ]]; then
    ok "verified ${RESTORED}/${JOBAPPS} job applications present"
    ssh_prod "cd '${PROD_ROOT}' && wp db query \"
      DROP TABLE IF EXISTS ${PREFIX}_hold_jobapps;
      DROP TABLE IF EXISTS ${PREFIX}_hold_jobmeta;\" --skip-plugins --skip-themes" >/dev/null 2>&1
  else
    warn "Expected ${JOBAPPS} job applications, found ${RESTORED}. Holding tables KEPT for recovery."
  fi
fi

# Plugin state: staging has several deliberately disabled, production must not
# inherit that.
if [[ -n "$ACTIVE_PLUGINS_BEFORE" ]]; then
  ssh_prod "cd '${PROD_ROOT}' && wp option update active_plugins '${ACTIVE_PLUGINS_BEFORE}' --format=json --skip-plugins --skip-themes" >/dev/null 2>&1 \
    && ok "production plugin state preserved"
fi

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

log "Content push complete."
log "Rollback if needed: ${BACKUP_DIR}/database.sql.gz"
