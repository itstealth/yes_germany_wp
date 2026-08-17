#!/usr/bin/env bash
# Deploy the managed paths to a target environment.
#
# Files are staged into a temporary directory on the remote and only moved into
# place once the whole transfer has succeeded, so a dropped connection cannot
# leave a half-written theme serving traffic. If any path fails to swap, every
# path already swapped is put back.
#
# Usage: deploy.sh <staging|production>

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

configure_environment "${1:?usage: deploy.sh <staging|production>}"

preflight
assert_environment "$ENVIRONMENT"

cd "$REPO_ROOT"

# ---------------------------------------------------------------------------
# 1. Verify the build is complete locally before touching the server
# ---------------------------------------------------------------------------
for p in "${MANAGED_PATHS[@]}"; do
  [[ -e "$p" ]] || die "Managed path missing from build: ${p}"
done
ok "${#MANAGED_PATHS[@]} managed path(s) present"

RELEASE_ID="$(date -u +%Y-%m-%d-%H%M%S)-${GITHUB_SHA:0:7}"
REMOTE_TMP="${REMOTE_ROOT}/.deploy-${RELEASE_ID}"

# ---------------------------------------------------------------------------
# 2. Stage the transfer
# ---------------------------------------------------------------------------
log "Staging release ${RELEASE_ID} → ${REMOTE_TMP}"
remote "mkdir -p '${REMOTE_TMP}'"
transfer_paths "$REMOTE_TMP" "${MANAGED_PATHS[@]}" \
  || die "Transfer failed — nothing on the server was modified."
ok "transfer complete"

# ---------------------------------------------------------------------------
# 3. Swap into place, with automatic backout
# ---------------------------------------------------------------------------
log "Activating release ${RELEASE_ID}"
remote "
set -e
ROOT='${REMOTE_ROOT}'
TMP='${REMOTE_TMP}'
BACKOUT=\"\${TMP}/.backout\"
mkdir -p \"\$BACKOUT\"

swapped=''
restore() {
  echo 'Swap failed — restoring previous state' >&2
  for p in \$swapped; do
    key=\$(echo \"\$p\" | tr / _)
    rm -rf \"\${ROOT}/\${p}\"
    [ -e \"\${BACKOUT}/\${key}\" ] && mv \"\${BACKOUT}/\${key}\" \"\${ROOT}/\${p}\"
  done
  exit 1
}
trap restore ERR

for p in $(printf '%s ' "${MANAGED_PATHS[@]}"); do
  src=\"\${TMP}/\$(basename \$p)\"
  [ -e \"\$src\" ] || src=\"\${TMP}/\${p}\"
  dst=\"\${ROOT}/\${p}\"
  [ -e \"\$src\" ] || continue
  mkdir -p \"\$(dirname \"\$dst\")\"
  if [ -e \"\$dst\" ]; then
    mv \"\$dst\" \"\${BACKOUT}/\$(echo \"\$p\" | tr / _)\"
  fi
  mv \"\$src\" \"\$dst\"
  swapped=\"\$swapped \$p\"
done

trap - ERR
# Keep the backout until the health check has had its say.
mkdir -p \"\${ROOT}/../.releases\" 2>/dev/null || true
echo 'swap ok'
" || die "Activation failed; the previous release was restored."
ok "release ${RELEASE_ID} active"

# Record where the previous copy went, so rollback.sh can find it.
remote "echo '${REMOTE_TMP}' > '${REMOTE_ROOT}/.last-release-backout'" 2>/dev/null || true

# ---------------------------------------------------------------------------
# 4. Post-deploy
# ---------------------------------------------------------------------------
log "Post-deploy tasks"

# Activate the child theme. Safe to re-run.
if [[ "${ACTIVATE_CHILD_THEME:-false}" == "true" ]]; then
  wp_remote theme activate zakra-child --skip-plugins --skip-themes \
    && ok "zakra-child active" || warn "could not activate zakra-child"
fi

# Apply pending SQL migrations, in order, exactly once.
if compgen -G "${REPO_ROOT}/migrations/*.sql" > /dev/null; then
  log "Applying migrations"
  bash "${SCRIPT_DIR}/migrate.sh" "$ENVIRONMENT"
fi

wp_remote cache flush --skip-plugins --skip-themes >/dev/null 2>&1 \
  && ok "object cache flushed" || warn "cache flush unavailable"

# Elementor caches compiled CSS; stale files cause visual regressions.
wp_remote elementor flush-css --skip-themes >/dev/null 2>&1 \
  && ok "Elementor CSS regenerated" || warn "Elementor flush unavailable"

log "Deploy complete: ${RELEASE_ID}"
echo "$RELEASE_ID" > .release-id
if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
  echo "release_id=${RELEASE_ID}" >> "$GITHUB_OUTPUT"
fi
