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
  # Dump straight from the mysql container rather than through WP-CLI:
  # `wp db export` shells out to the mysqldump binary, and the wordpress:cli
  # image ships the MariaDB client, which cannot authenticate against MySQL 8's
  # caching_sha2_password. Credentials are read from the server-side .env,
  # which is the only place they exist.
  remote "cd '${STACK_DIR}' && set -a && . ./.env && set +a && \
          docker compose exec -T db mysqldump \
            -u root -p\"\$DB_ROOT_PASSWORD\" \
            --single-transaction --quick --default-character-set=utf8mb4 \
            --routines --events \"\$DB_NAME\" 2>/dev/null \
          | gzip -6 > '${DEST}/database.sql.gz'" \
    || die "Database export failed — aborting before any files are touched."
else
  # Only this site's tables. The client's database is shared with eleven other
  # sites — 642 tables, 1,059 MB, of which 178 are ours. An unscoped dump wrote
  # a copy of other clients' data into this site's backup directory and took
  # minutes doing it. The prefix comes from the live wp-config, not a guess.
  PREFIX_LIVE="$(remote "cd '${REMOTE_ROOT}' && wp eval 'global \$wpdb; echo \$wpdb->prefix;' --skip-plugins --skip-themes 2>/dev/null" | tr -d '\r\n')"
  [[ -n "$PREFIX_LIVE" ]] || die "Could not read the table prefix — refusing to guess at backup scope."

  TABLES="$(remote "cd '${REMOTE_ROOT}' && wp db query \"SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '${PREFIX_LIVE}%';\" --skip-column-names --skip-plugins --skip-themes 2>/dev/null" | tr -d '\r' | grep -E '^[A-Za-z0-9_]+$' | paste -sd' ' -)"
  [[ -n "$TABLES" ]] || die "No tables matched prefix '${PREFIX_LIVE}' — refusing to take an empty backup."
  log "  scoping backup to $(printf '%s\n' $TABLES | wc -l) table(s) with prefix ${PREFIX_LIVE}"

  remote "cd '${REMOTE_ROOT}' && wp db export - --tables='$(printf '%s' "$TABLES" | tr ' ' ',')' \
            --single-transaction --quick --skip-plugins --skip-themes \
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
