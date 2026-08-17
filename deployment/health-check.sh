#!/usr/bin/env bash
# Verify a deployment actually works. Copying files is not success.
#
# Exits non-zero on any hard failure so the workflow triggers a rollback.
#
# Usage: health-check.sh <staging|production> <base-url>

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

configure_environment "${1:?usage: health-check.sh <env> <base-url>}"
BASE_URL="${2:?usage: health-check.sh <env> <base-url>}"
BASE_URL="${BASE_URL%/}"

FAILURES=0
fail() { printf '\033[0;31m  ✗\033[0m %s\n' "$*"; FAILURES=$((FAILURES+1)); }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Staging sits behind HTTP basic auth so a copy of the client's site is not
# publicly browsable. Supply HEALTH_AUTH as "user:pass" to get past it.
CURL_AUTH=()
if [[ -n "${HEALTH_AUTH:-}" ]]; then
  CURL_AUTH=(-u "$HEALTH_AUTH")
fi

log "Health check: ${ENVIRONMENT} (${BASE_URL})"

# ---------------------------------------------------------------------------
# 1. Homepage responds and looks like WordPress
# ---------------------------------------------------------------------------
CODE="$(curl -sS "${CURL_AUTH[@]}" -o "${TMP}/home.html" -w '%{http_code}' -L --max-time 120 "${BASE_URL}/" || echo 000)"
[[ "$CODE" == "200" ]] && ok "homepage HTTP 200" || fail "homepage returned HTTP ${CODE}"

if [[ -s "${TMP}/home.html" ]]; then
  BYTES=$(wc -c < "${TMP}/home.html")
  if [[ "$BYTES" -lt 500 ]]; then
    fail "homepage body suspiciously small (${BYTES} bytes)"
  else
    ok "homepage body ${BYTES} bytes"
  fi

  # A fatal error frequently still returns 200, so inspect the body.
  if grep -qiE 'fatal error|parse error|there has been a critical error|error establishing a database' "${TMP}/home.html"; then
    fail "homepage contains a PHP or database error"
  else
    ok "no PHP/database errors in output"
  fi
else
  fail "homepage returned an empty body"
fi

# ---------------------------------------------------------------------------
# 2. REST API
# ---------------------------------------------------------------------------
REST="$(curl -sS "${CURL_AUTH[@]}" -o "${TMP}/rest.json" -w '%{http_code}' -L --max-time 120 "${BASE_URL}/wp-json/" || echo 000)"
if [[ "$REST" == "200" ]] && grep -q '"namespaces"' "${TMP}/rest.json" 2>/dev/null; then
  ok "REST API responding"
else
  fail "REST API check failed (HTTP ${REST})"
fi

# ---------------------------------------------------------------------------
# 3. Database reachable from the application
# ---------------------------------------------------------------------------
# Deliberately not `wp db check`: that shells out to the mysql client binary,
# and the wordpress:cli image ships the MariaDB client, which cannot
# authenticate against MySQL 8's caching_sha2_password. Going through
# WordPress's own PHP layer also tests the path the site actually uses.
DB_PROBE="$(wp_remote eval "'global \$wpdb; echo \$wpdb->get_var( \"SELECT COUNT(*) FROM {\$wpdb->posts}\" );'" --skip-plugins --skip-themes 2>/dev/null | tr -dc '0-9' || true)"
if [[ -n "$DB_PROBE" && "$DB_PROBE" -gt 0 ]]; then
  ok "database reachable (${DB_PROBE} posts)"
else
  fail "database unreachable via WordPress"
fi

# ---------------------------------------------------------------------------
# 4. Core boots
# ---------------------------------------------------------------------------
WP_VER="$(wp_remote core version 2>/dev/null | tr -d '\r\n' || true)"
[[ -n "$WP_VER" ]] && ok "WordPress core loads (v${WP_VER})" || fail "core failed to load via WP-CLI"

# ---------------------------------------------------------------------------
# 5. The child theme is the active theme
# ---------------------------------------------------------------------------
EXPECTED_THEME="${EXPECTED_THEME:-zakra-child}"
ACTIVE="$(wp_remote theme list --status=active --field=name --skip-plugins --skip-themes 2>/dev/null | tr -d '\r\n' || true)"
if [[ "$ACTIVE" == "$EXPECTED_THEME" ]]; then
  ok "active theme is ${ACTIVE}"
else
  # Not fatal before the first theme switch, but always worth surfacing.
  warn "active theme is '${ACTIVE}', expected '${EXPECTED_THEME}'"
fi

# ---------------------------------------------------------------------------
# 6. Plugins enumerate (proves WP boots far enough to load them)
# ---------------------------------------------------------------------------
COUNT="$(wp_remote plugin list --status=active --format=count 2>/dev/null | tr -d '\r\n' || echo 0)"
[[ "${COUNT:-0}" -gt 0 ]] && ok "${COUNT} active plugins" || fail "could not enumerate plugins"

# ---------------------------------------------------------------------------
# 7. Staging must not be publicly indexable, and must not be live-mailing
# ---------------------------------------------------------------------------
if [[ "$ENVIRONMENT" != "production" ]]; then
  if curl -sSI "${CURL_AUTH[@]}" --max-time 30 "${BASE_URL}/" | grep -qi 'x-robots-tag: *noindex'; then
    ok "noindex header present"
  else
    fail "staging is missing its noindex header"
  fi

  ENV_TYPE="$(wp_remote config get WP_ENVIRONMENT_TYPE --skip-plugins --skip-themes 2>/dev/null | tr -d '\r\n' || echo unset)"
  [[ "$ENV_TYPE" == "staging" ]] && ok "WP_ENVIRONMENT_TYPE=staging" \
    || fail "WP_ENVIRONMENT_TYPE is '${ENV_TYPE}', expected 'staging'"
fi

# ---------------------------------------------------------------------------
# 8. Recent fatals in the debug log
# ---------------------------------------------------------------------------
FATALS="$(remote "test -f '${REMOTE_ROOT}/wp-content/debug.log' && tail -200 '${REMOTE_ROOT}/wp-content/debug.log' | grep -ci 'PHP Fatal' || echo 0" | tr -d '\r\n')"
[[ "${FATALS:-0}" -gt 0 ]] && fail "${FATALS} PHP fatal(s) in recent debug.log" || ok "no recent PHP fatals"

echo
if [[ "$FAILURES" -gt 0 ]]; then
  die "Health check FAILED with ${FAILURES} problem(s)."
fi
log "Health check PASSED"
