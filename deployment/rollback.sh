#!/usr/bin/env bash
# Restore the previous CODE release.
#
# This deliberately does NOT restore the database. A failed code deploy is not a
# reason to roll live content backwards: production holds leads, form entries and
# user accounts created since the backup, and restoring the database would
# destroy them. If a database restore is genuinely required it is a manual,
# deliberate operation — see docs/runbook.md.
#
# Usage: rollback.sh <staging|production> [backup-stamp]

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

configure_environment "${1:?usage: rollback.sh <staging|production> [backup-stamp]}"
STAMP="${2:-${BACKUP_STAMP:-}}"

log "Rolling back ${ENVIRONMENT} (code only — database untouched)"

# ---------------------------------------------------------------------------
# Preferred path: the backout directory the deploy just created.
# ---------------------------------------------------------------------------
BACKOUT_ROOT="$(remote "cat '${REMOTE_ROOT}/.last-release-backout' 2>/dev/null || true" | tr -d '\r\n')"

if [[ -n "$BACKOUT_ROOT" ]] && remote "test -d '${BACKOUT_ROOT}/.backout'"; then
  log "Restoring from backout: ${BACKOUT_ROOT}/.backout"
  remote "
set -e
ROOT='${REMOTE_ROOT}'
BACKOUT='${BACKOUT_ROOT}/.backout'
restored=0
for f in \"\$BACKOUT\"/*; do
  [ -e \"\$f\" ] || continue
  key=\$(basename \"\$f\")
  # Keys were written as path-with-slashes-replaced-by-underscores.
  target=\"\${ROOT}/\$(echo \"\$key\" | sed 's|_|/|g')\"
  if [ -e \"\$target\" ] || [ -e \"\$(dirname \"\$target\")\" ]; then
    rm -rf \"\$target\"
    mv \"\$f\" \"\$target\"
    restored=\$((restored+1))
  fi
done
echo \"restored \$restored path(s)\"
" && ok "previous release restored"

# ---------------------------------------------------------------------------
# Fallback: unpack release.tar.gz from a named backup.
# ---------------------------------------------------------------------------
elif [[ -n "$STAMP" ]]; then
  ARCHIVE="${REMOTE_BACKUP_DIR}/${STAMP}/release.tar.gz"
  remote "test -f '${ARCHIVE}'" || die "No release archive at ${ARCHIVE}"
  log "Restoring from ${ARCHIVE}"
  remote "cd '${REMOTE_ROOT}' && tar xzf '${ARCHIVE}'" \
    && ok "release ${STAMP} restored"

else
  die "Nothing to roll back to: no backout directory and no backup stamp given."
fi

# ---------------------------------------------------------------------------
# Clear caches so the restored code is actually what gets served.
# ---------------------------------------------------------------------------
wp_remote cache flush --skip-plugins --skip-themes >/dev/null 2>&1 \
  && ok "cache flushed" || warn "cache flush unavailable"
wp_remote elementor flush-css --skip-themes >/dev/null 2>&1 || true

if [[ "$USE_DOCKER" == "true" ]]; then
  remote "cd '${STACK_DIR}' && docker compose restart php" >/dev/null 2>&1 \
    && ok "php container restarted" || warn "container restart failed"
fi

log "Rollback complete. The database was not modified."
