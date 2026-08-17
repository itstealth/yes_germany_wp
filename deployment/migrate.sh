#!/usr/bin/env bash
# Apply SQL migrations, in filename order, exactly once per environment.
#
# This exists because most of this site's configuration lives in the database,
# not in files: 52 WPCode snippets, 526 header/footer entries, 284 Elementor
# templates. A code-only deploy cannot carry those, so any deliberate database
# change is expressed as a numbered migration and reviewed like code.
#
# Applied migrations are tracked in a table, so re-running is safe.
#
# Naming: migrations/0001-short-description.sql
#
# Usage: migrate.sh <staging|production>

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

configure_environment "${1:?usage: migrate.sh <staging|production>}"

MIGRATIONS_DIR="${REPO_ROOT}/migrations"
TRACKING_TABLE="yg_migrations"

if ! compgen -G "${MIGRATIONS_DIR}/*.sql" > /dev/null; then
  log "No migrations to apply."
  exit 0
fi

assert_environment "$ENVIRONMENT"

# ---------------------------------------------------------------------------
# Tracking table
# ---------------------------------------------------------------------------
PREFIX="$(wp_remote config get table_prefix --skip-plugins --skip-themes 2>/dev/null | tr -d '\r\n')"
[[ -n "$PREFIX" ]] || die "Could not determine the table prefix."
TABLE="${PREFIX}${TRACKING_TABLE}"

wp_remote db query "\"CREATE TABLE IF NOT EXISTS ${TABLE} (
  id INT AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL UNIQUE,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\"" >/dev/null \
  || die "Could not create the migration tracking table."

APPLIED="$(wp_remote db query "\"SELECT filename FROM ${TABLE};\"" --skip-column-names 2>/dev/null | tr -d '\r' || true)"

# ---------------------------------------------------------------------------
# Apply, in order
# ---------------------------------------------------------------------------
COUNT=0
for file in $(find "$MIGRATIONS_DIR" -maxdepth 1 -name '*.sql' | sort); do
  name="$(basename "$file")"

  if grep -qxF "$name" <<< "$APPLIED"; then
    ok "already applied: ${name}"
    continue
  fi

  sum="$(sha256sum "$file" | cut -d' ' -f1)"
  log "Applying ${name}"

  # Pipe the file into WP-CLI rather than inlining it, so quoting in the SQL
  # cannot be mangled by the shell.
  if [[ "$USE_DOCKER" == "true" ]]; then
    # shellcheck disable=SC2086
    ssh $(ssh_opts) "${DEPLOY_USER}@${DEPLOY_HOST}" \
      "cd '${STACK_DIR}' && docker compose exec -T --user www-data php wp db query --path=/var/www/html" \
      < "$file" || die "Migration ${name} failed. Database left as-is; fix and re-run."
  else
    # shellcheck disable=SC2086
    ssh $(ssh_opts) "${DEPLOY_USER}@${DEPLOY_HOST}" \
      "cd '${REMOTE_ROOT}' && wp db query" < "$file" \
      || die "Migration ${name} failed. Database left as-is; fix and re-run."
  fi

  wp_remote db query "\"INSERT INTO ${TABLE} (filename, checksum, applied_at)
                       VALUES ('${name}', '${sum}', UTC_TIMESTAMP());\"" >/dev/null \
    || warn "Applied ${name} but could not record it — re-running may duplicate it."

  ok "applied ${name}"
  COUNT=$((COUNT+1))
done

log "Migrations complete (${COUNT} newly applied)."
