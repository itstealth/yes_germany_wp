#!/usr/bin/env bash
# Rebuild staging from production.
#
# Run this before starting a batch of work. Staging drifts from production every
# time anyone edits the live site, and push-content.sh refuses to publish while
# production is ahead — so a refresh is a routine part of the loop, not a repair:
#
#     refresh → work on staging → dry run → push → live
#
# Production is only ever READ. The database is streamed out, never dumped to a
# file on the client's disk.
#
# The refresh is destructive to STAGING: its database is dropped and rebuilt, so
# anything unpublished there is lost. It prints what would be lost and requires
# --yes to proceed.
#
# Usage: refresh-staging.sh [--yes] [--skip-uploads]

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

ASSUME_YES=false
SKIP_UPLOADS=false
for a in "$@"; do
  case "$a" in
    --yes)          ASSUME_YES=true ;;
    --skip-uploads) SKIP_UPLOADS=true ;;
    *) die "Unknown argument: $a" ;;
  esac
done

STG_HOST="${STAGING_HOST:-100.66.61.11}"
STG_USER="${STAGING_USER:-ygdeploy}"
STG_PORT="${STAGING_PORT:-2222}"
STG_DIR="${STAGING_STACK_DIR:-/opt/yesgermany/staging}"
STG_ROOT="${STG_DIR}/wordpress"
STG_URL="${STG_URL:-https://yg-staging.stealthlearn.in}"

PROD_HOST="${PROD_HOST:?PROD_HOST is required}"
PROD_USER="${PROD_USER:?PROD_USER is required}"
PROD_PORT="${PROD_PORT:-22}"
PROD_ROOT="${PROD_ROOT:?PROD_ROOT is required}"
PROD_URL="${PROD_URL:-https://www.yesgermany.com}"
PREFIX="${TABLE_PREFIX:-wpb9_}"

STG_KEY="${STAGING_SSH_KEY_FILE:-${HOME}/.ssh/deploy_key}"
PROD_KEY="${PROD_SSH_KEY_FILE:-${HOME}/.ssh/prod_deploy_key}"
# AddressFamily=inet: production's hostname carries an AAAA record, and GitHub
# runners have no IPv6 route. Without this, ssh picks the v6 address and dies
# with "Network is unreachable" — which is how the 2026-09-01 health check failed
# after a publish that had already succeeded.
SSH_BASE="-o AddressFamily=inet -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=20 -o ServerAliveInterval=20"

# -n on the query variants: ssh reads stdin, and inside "$( )" that swallows the
# script's input and returns an empty string.
ssh_stg()    { ssh    -i "$STG_KEY"  -p "$STG_PORT"  $SSH_BASE "${STG_USER}@${STG_HOST}" "$@"; }
ssh_stg_q()  { ssh -n -i "$STG_KEY"  -p "$STG_PORT"  $SSH_BASE "${STG_USER}@${STG_HOST}" "$@"; }
ssh_prod_q() { ssh -n -i "$PROD_KEY" -p "$PROD_PORT" $SSH_BASE "${PROD_USER}@${PROD_HOST}" "$@"; }

[[ -f "$STG_KEY"  ]] || die "Staging key not found at ${STG_KEY}"
[[ -f "$PROD_KEY" ]] || die "Production key not found at ${PROD_KEY}"

log "Refresh staging from production"

# ---------------------------------------------------------------------------
# 1. Confirm the target really is staging.
# ---------------------------------------------------------------------------
STG_ENV="$(ssh_stg_q "grep -oE \"WP_ENVIRONMENT_TYPE'[^)]*\" '${STG_ROOT}/wp-config.php' | grep -oE \"'(staging|production|development)'\" | tr -d \"'\" | head -1" | tr -dc 'a-z')"
[[ "$STG_ENV" == "staging" ]] || die "Target reports '${STG_ENV}', not staging. Refusing to drop its database."
ok "target confirmed: staging"

# ---------------------------------------------------------------------------
# 2. Say what the refresh will destroy, before destroying it.
# ---------------------------------------------------------------------------
STG_NEWEST="$(ssh_stg_q "cd '${STG_DIR}' && set -a && . ./.env && set +a && \
  docker compose exec -T db mysql -u root -p\"\$DB_ROOT_PASSWORD\" -N \"\$DB_NAME\" -e \
  \"SELECT COALESCE(MAX(post_modified),'-') FROM ${PREFIX}posts WHERE post_type NOT IN ('revision') AND post_status NOT IN ('auto-draft','inherit','trash');\" 2>/dev/null" \
  | tr -d '\r' | grep -E '^[0-9]{4}-|^-' | head -1)"
PROD_NEWEST="$(ssh_prod_q "cd '${PROD_ROOT}' && wp db query \"SELECT COALESCE(MAX(post_modified),'-') FROM ${PREFIX}posts WHERE post_type NOT IN ('revision') AND post_status NOT IN ('auto-draft','inherit','trash');\" --skip-column-names 2>/dev/null" \
  | tr -d '\r' | grep -E '^[0-9]{4}-|^-' | head -1)"

log "  staging newest content:    ${STG_NEWEST}"
log "  production newest content: ${PROD_NEWEST}"

if [[ "$ASSUME_YES" != "true" ]]; then
  warn "This DROPS the staging database. Unpublished work on staging will be lost."
  warn "Re-run with --yes to proceed."
  exit 1
fi

# ---------------------------------------------------------------------------
# 3. Keep a copy of staging first — cheap, and the only way back.
# ---------------------------------------------------------------------------
STAMP="$(date -u +%Y-%m-%d-%H%M%S)"
log "Snapshotting current staging → /opt/yesgermany/backups/staging/pre-refresh-${STAMP}"
ssh_stg "mkdir -p /opt/yesgermany/backups/staging/pre-refresh-${STAMP} && cd '${STG_DIR}' && \
  set -a && . ./.env && set +a && \
  docker compose exec -T db mysqldump -u root -p\"\$DB_ROOT_PASSWORD\" --single-transaction --quick \
    --default-character-set=utf8mb4 \"\$DB_NAME\" 2>/dev/null \
  | gzip -6 > /opt/yesgermany/backups/staging/pre-refresh-${STAMP}/database.sql.gz" \
  && ok "staging snapshot taken" || warn "snapshot failed — continuing anyway"

# ---------------------------------------------------------------------------
# 4. Stream production's tables straight into staging.
#
# Only this site's tables: the production database is shared with 11 other
# WordPress installs and a student-records application, none of which belong
# here. Nothing is written to the client's disk.
# ---------------------------------------------------------------------------
log "Copying production database (${PREFIX}* tables only)"
TBLS="$(ssh_prod_q "cd '${PROD_ROOT}' && wp db tables '${PREFIX}*' --all-tables-with-prefix --format=csv 2>/dev/null" | tr -d '\r' | tr ',' ' ')"
[[ -n "$TBLS" ]] || die "Could not list production tables."
COUNT=$(wc -w <<< "$TBLS")
ok "${COUNT} tables to copy"

TMP="$(mktemp)"; trap 'rm -f "$TMP"' EXIT
ssh_prod_q "cd '${PROD_ROOT}' && wp db export - --tables=\"$(tr ' ' ',' <<< "$TBLS" | sed 's/,$//')\" \
   --single-transaction --quick --default-character-set=utf8mb4 --skip-plugins --skip-themes 2>/dev/null" \
  | gzip -6 > "$TMP" || die "Export from production failed."
[[ -s "$TMP" ]] || die "Production export is empty — refusing to wipe staging."
ok "exported $(du -h "$TMP" | cut -f1) (compressed)"

log "Importing into staging"
ssh_stg "cd '${STG_DIR}' && set -a && . ./.env && set +a && \
  docker compose exec -T db mysql -u root -p\"\$DB_ROOT_PASSWORD\" -e \
    \"DROP DATABASE IF EXISTS \\\`\$DB_NAME\\\`;
      CREATE DATABASE \\\`\$DB_NAME\\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      GRANT ALL ON \\\`\$DB_NAME\\\`.* TO '\$DB_USER'@'%'; FLUSH PRIVILEGES;\" 2>/dev/null" \
  || die "Could not recreate the staging database."

gzip -dc "$TMP" | ssh_stg "cd '${STG_DIR}' && set -a && . ./.env && set +a && \
  docker compose exec -T db mysql -u root -p\"\$DB_ROOT_PASSWORD\" --default-character-set=utf8mb4 \"\$DB_NAME\"" \
  || die "Import into staging failed."
ok "database imported"

# ---------------------------------------------------------------------------
# 5. Point it at staging, then make it safe to work in.
# ---------------------------------------------------------------------------
# URL rewriting is done by sanitize-staging.sh, which runs next. Doing it here
# too meant eight full-table search-replaces on a ~950 MB database instead of
# four — several minutes of duplicated work for no benefit.

log "Sanitizing personal data (includes URL rewrite)"
bash "${SCRIPT_DIR}/sanitize-staging.sh" 2>&1 | grep -E "✓|✗|!" | sed 's/^/  /' || warn "sanitize reported problems"

log "Hardening"
bash "${SCRIPT_DIR}/harden-staging.sh" 2>&1 | grep -E "deactivated|removed|✓ blog_public" | sed 's/^/  /' || warn "harden reported problems"

# ---------------------------------------------------------------------------
# 6. New media added on production since last time.
# ---------------------------------------------------------------------------
if [[ "$SKIP_UPLOADS" != "true" ]]; then
  log "Syncing new media from production (additive)"
  ssh_prod_q "cd '${PROD_ROOT}/wp-content' && tar czf - uploads 2>/dev/null" \
    | ssh_stg "cd '${STG_ROOT}/wp-content' && tar xzf - --skip-old-files 2>/dev/null" \
    && ok "media synced" || warn "media sync had problems — re-run with --skip-uploads to skip"
  ssh_stg "chown -R 100033:ygdeploy '${STG_ROOT}/wp-content/uploads' 2>/dev/null" || true
else
  warn "skipping media sync"
fi

# ---------------------------------------------------------------------------
# 7. Clear caches so the refreshed content is what gets served.
# ---------------------------------------------------------------------------
ssh_stg_q "cd '${STG_DIR}' && docker compose run --rm -T wpcli wp cache flush --path=/var/www/html --skip-plugins --skip-themes" >/dev/null 2>&1 || true
ssh_stg_q "cd '${STG_DIR}' && docker compose run --rm -T wpcli wp elementor flush-css --path=/var/www/html --skip-themes" >/dev/null 2>&1 || true
ssh_stg "cd '${STG_DIR}' && docker compose restart php" >/dev/null 2>&1 || true
ok "caches cleared"

echo
log "Staging refreshed. Snapshot of the previous state:"
log "  /opt/yesgermany/backups/staging/pre-refresh-${STAMP}/database.sql.gz"
