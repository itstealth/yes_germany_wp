#!/usr/bin/env bash
# Back up the database and the current release BEFORE anything is changed.
#
# Backups are written outside the document root so they can never be downloaded
# over HTTP, and a retention limit is applied afterwards.
#
# Usage: backup.sh <staging|production>

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

configure_environment "${1:?usage: backup.sh <staging|production>}"
assert_backup_dir_safe

RETAIN="${BACKUP_RETAIN:-10}"
STAMP="$(date -u +%Y-%m-%d-%H%M%S)"
DEST="${REMOTE_BACKUP_DIR}/${STAMP}"

log "Backing up ${ENVIRONMENT} → ${DEST}"

remote "mkdir -p '${DEST}' && chmod 700 '${DEST}'"

# ---------------------------------------------------------------------------
# 1. Database
#
# This runs first and aborts on failure: without a restorable database we do
# not touch a single file.
# ---------------------------------------------------------------------------
log "Exporting database"
if [[ "$USE_DOCKER" == "true" ]]; then
  remote "cd '${STACK_DIR}' && docker compose exec -T --user www-data php \
            wp db export - --single-transaction --quick --skip-plugins --skip-themes --path=/var/www/html \
          | gzip -6 > '${DEST}/database.sql.gz'" \
    || die "Database export failed — aborting before any files are touched."
else
  remote "cd '${REMOTE_ROOT}' && wp db export - --single-transaction --quick --skip-plugins --skip-themes \
          | gzip -6 > '${DEST}/database.sql.gz'" \
    || die "Database export failed — aborting before any files are touched."
fi

# A gzip that does not decompress is not a backup.
remote "gzip -t '${DEST}/database.sql.gz'" || die "Database backup is corrupt (failed gzip -t)."
DB_SIZE="$(remote "du -h '${DEST}/database.sql.gz' | cut -f1")"
ok "database.sql.gz (${DB_SIZE}) verified"

# ---------------------------------------------------------------------------
# 2. Current release — only the paths this repo manages
# ---------------------------------------------------------------------------
log "Archiving current release"
EXISTING=()
for p in "${MANAGED_PATHS[@]}"; do
  if remote "test -e '${REMOTE_ROOT}/${p}'"; then
    EXISTING+=("$p")
  else
    warn "not present yet, skipping: ${p}"
  fi
done

if [[ ${#EXISTING[@]} -gt 0 ]]; then
  remote "cd '${REMOTE_ROOT}' && tar czf '${DEST}/release.tar.gz' ${EXISTING[*]}"
  REL_SIZE="$(remote "du -h '${DEST}/release.tar.gz' | cut -f1")"
  ok "release.tar.gz (${REL_SIZE})"
else
  warn "No managed paths existed — treating as a first deploy."
fi

# ---------------------------------------------------------------------------
# 3. Manifest, so a restore is auditable
# ---------------------------------------------------------------------------
remote "cat > '${DEST}/manifest.txt' <<EOF
environment=${ENVIRONMENT}
created_utc=${STAMP}
git_sha=${GITHUB_SHA:-unknown}
git_ref=${GITHUB_REF:-unknown}
actor=${GITHUB_ACTOR:-manual}
run_id=${GITHUB_RUN_ID:-none}
remote_root=${REMOTE_ROOT}
EOF"

# ---------------------------------------------------------------------------
# 4. Retention
# ---------------------------------------------------------------------------
log "Applying retention (keeping newest ${RETAIN})"
remote "cd '${REMOTE_BACKUP_DIR}' && ls -1d */ 2>/dev/null | sort -r | tail -n +\$((${RETAIN}+1)) | xargs -r rm -rf"
KEPT="$(remote "cd '${REMOTE_BACKUP_DIR}' && ls -1d */ 2>/dev/null | wc -l")"
ok "${KEPT} backup(s) retained"

echo "$STAMP" > .backup-stamp
if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
  echo "backup_stamp=${STAMP}" >> "$GITHUB_OUTPUT"
fi

log "Backup complete: ${DEST}"
