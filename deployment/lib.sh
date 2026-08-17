#!/usr/bin/env bash
# Shared helpers for the yes_germany_wp deployment scripts.
#
# Two very different targets are supported:
#
#   staging     Docker stack on the OVH host. WP-CLI runs inside the php
#               container; rsync is available.
#   production  Jailed cPanel shell on the client's server. No root, no Docker,
#               and no rsync — transfers there must use tar over SSH.
#
# Anything environment-specific is resolved through the functions below rather
# than branching inline in each script.

set -Eeuo pipefail

# ---------------------------------------------------------------------------
# Paths this repository owns on the server.
# Anything not listed here is never touched by a deploy. Uploads, wp-config.php,
# .htaccess and third-party plugins are all deliberately absent.
# ---------------------------------------------------------------------------
MANAGED_PATHS=(
  "wp-content/themes/zakra-child"
  "wp-content/mu-plugins"
  "wp-content/plugins/yesgermany-schema-engine"
)

log()  { printf '\033[0;36m[%s]\033[0m %s\n' "$(date -u +%H:%M:%S)" "$*"; }
ok()   { printf '\033[0;32m  ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[0;33m  !\033[0m %s\n' "$*"; }
die()  { printf '\033[0;31m  ✗ %s\033[0m\n' "$*" >&2; exit 1; }

require_env() {
  local name="$1"
  [[ -n "${!name:-}" ]] || die "Required environment variable ${name} is not set."
}

# ---------------------------------------------------------------------------
# Environment configuration
# ---------------------------------------------------------------------------
configure_environment() {
  ENVIRONMENT="${1:?environment required}"

  case "$ENVIRONMENT" in
    staging)
      DEPLOY_HOST="${DEPLOY_HOST:-100.66.61.11}"
      DEPLOY_USER="${DEPLOY_USER:-root}"
      STACK_DIR="${STACK_DIR:-/opt/yesgermany/staging}"
      REMOTE_ROOT="${REMOTE_ROOT:-${STACK_DIR}/wordpress}"
      REMOTE_BACKUP_DIR="${REMOTE_BACKUP_DIR:-/opt/yesgermany/backups/staging}"
      USE_DOCKER=true
      HAS_RSYNC=true
      ;;
    production)
      require_env DEPLOY_HOST
      require_env DEPLOY_USER
      require_env REMOTE_ROOT
      REMOTE_BACKUP_DIR="${REMOTE_BACKUP_DIR:?REMOTE_BACKUP_DIR is required for production}"
      USE_DOCKER=false
      # The client's cPanel shell has no rsync; verified directly on the host.
      HAS_RSYNC=false
      ;;
    *)
      die "Unknown environment '${ENVIRONMENT}'. Expected 'staging' or 'production'."
      ;;
  esac

  DEPLOY_PORT="${DEPLOY_PORT:-22}"
  export ENVIRONMENT DEPLOY_HOST DEPLOY_USER REMOTE_ROOT REMOTE_BACKUP_DIR \
         STACK_DIR USE_DOCKER HAS_RSYNC DEPLOY_PORT
}

# Refuse to place backups anywhere a browser could reach.
assert_backup_dir_safe() {
  case "$REMOTE_BACKUP_DIR" in
    "${REMOTE_ROOT}"*)
      die "REMOTE_BACKUP_DIR (${REMOTE_BACKUP_DIR}) is inside the document root; backups would be publicly downloadable."
      ;;
  esac
}

# ---------------------------------------------------------------------------
# SSH
# ---------------------------------------------------------------------------
ssh_opts() {
  local key_opt=""
  [[ -f "${HOME}/.ssh/deploy_key" ]] && key_opt="-i ${HOME}/.ssh/deploy_key"
  echo "${key_opt} -p ${DEPLOY_PORT} -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15"
}

remote() {
  # shellcheck disable=SC2086
  ssh $(ssh_opts) "${DEPLOY_USER}@${DEPLOY_HOST}" "$@"
}

# ---------------------------------------------------------------------------
# WP-CLI — same call signature regardless of Docker
# ---------------------------------------------------------------------------
wp_remote() {
  if [[ "$USE_DOCKER" == "true" ]]; then
    # WP-CLI is NOT present in the wordpress:*-fpm image, so it runs as a
    # one-shot container from the dedicated wpcli service instead.
    remote "cd '${STACK_DIR}' && docker compose run --rm -T wpcli wp $* --path=/var/www/html"
  else
    remote "cd '${REMOTE_ROOT}' && wp $*"
  fi
}

# ---------------------------------------------------------------------------
# Raw SQL against the target database.
#
# Deliberately NOT `wp db query`: that shells out to the mysql client binary,
# and the wordpress:cli image ships the MariaDB client, which cannot
# authenticate against MySQL 8's caching_sha2_password. On Docker targets we
# talk to the mysql container directly, reading credentials from the
# server-side .env — the only place they exist.
# ---------------------------------------------------------------------------
db_query() {
  local sql="$1"
  if [[ "$USE_DOCKER" == "true" ]]; then
    remote "cd '${STACK_DIR}' && set -a && . ./.env && set +a && \
            docker compose exec -T db mysql -u root -p\"\$DB_ROOT_PASSWORD\" -N \"\$DB_NAME\" -e '${sql}' 2>/dev/null"
  else
    remote "cd '${REMOTE_ROOT}' && wp db query \"${sql}\" --skip-column-names --skip-plugins --skip-themes"
  fi
}

# Pipe a local SQL file into the target database.
db_import_file() {
  local file="$1"
  if [[ "$USE_DOCKER" == "true" ]]; then
    # shellcheck disable=SC2086
    ssh $(ssh_opts) "${DEPLOY_USER}@${DEPLOY_HOST}" \
      "cd '${STACK_DIR}' && set -a && . ./.env && set +a && \
       docker compose exec -T db mysql -u root -p\"\$DB_ROOT_PASSWORD\" \"\$DB_NAME\"" < "$file"
  else
    # shellcheck disable=SC2086
    ssh $(ssh_opts) "${DEPLOY_USER}@${DEPLOY_HOST}" \
      "cd '${REMOTE_ROOT}' && wp db query" < "$file"
  fi
}

# ---------------------------------------------------------------------------
# Preflight — confirm the target can take a deploy before changing anything
# ---------------------------------------------------------------------------
preflight() {
  log "Preflight on ${DEPLOY_USER}@${DEPLOY_HOST} (${ENVIRONMENT})"

  remote "test -d '${REMOTE_ROOT}'" || die "Document root ${REMOTE_ROOT} does not exist."
  ok "document root: ${REMOTE_ROOT}"

  remote "test -f '${REMOTE_ROOT}/wp-config.php' || test -f '${REMOTE_ROOT}/wp-load.php'" \
    || die "No WordPress found at ${REMOTE_ROOT} — refusing to deploy."
  ok "WordPress present"

  if [[ "$USE_DOCKER" == "true" ]]; then
    remote "cd '${STACK_DIR}' && docker compose ps --status running --quiet php | grep -q ." \
      || die "The php container is not running in ${STACK_DIR}."
    ok "php container running"
  fi

  remote "command -v tar >/dev/null" || die "tar unavailable on the remote host."
  ok "tar available"
}

# ---------------------------------------------------------------------------
# Guard against a mis-set secret pointing a staging deploy at production
# ---------------------------------------------------------------------------
assert_environment() {
  local expected="$1" actual
  actual="$(wp_remote config get WP_ENVIRONMENT_TYPE --skip-plugins --skip-themes 2>/dev/null | tr -d '\r\n' || echo unset)"

  if [[ "$actual" != "$expected" ]]; then
    die "Environment mismatch: remote reports '${actual}' but this deploy targets '${expected}'. Refusing."
  fi
  ok "environment confirmed: ${actual}"
}

# ---------------------------------------------------------------------------
# Transfer a set of paths to the remote, honouring rsync availability
# ---------------------------------------------------------------------------
transfer_paths() {
  local dest="$1"; shift
  local paths=("$@")

  if [[ "$HAS_RSYNC" == "true" ]]; then
    # shellcheck disable=SC2086
    rsync -az --delete-after --checksum \
      -e "ssh $(ssh_opts)" \
      "${paths[@]}" "${DEPLOY_USER}@${DEPLOY_HOST}:${dest}/"
  else
    # No rsync on the client's cPanel host — stream a tar instead.
    tar czf - --exclude-vcs --exclude='node_modules' --exclude='*.map' "${paths[@]}" \
      | remote "mkdir -p '${dest}' && tar xzf - -C '${dest}'"
  fi
}
