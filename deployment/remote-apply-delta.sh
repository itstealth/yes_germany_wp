#!/usr/bin/env bash
# Runs ON PRODUCTION. Applies a row-level content delta.
#
# Much smaller job than remote-apply.sh: that one replaces whole tables and so
# has to lift job applications, comments and users out of the way first. A delta
# only touches keys that were computed with those rows excluded, so nothing
# needs holding — but the counts are checked afterwards anyway, because "it
# cannot happen" is not evidence.
#
# The delta arrives as a single transaction. If it fails part-way, production
# keeps the old content rather than half of each.
#
# Usage (on production):  remote-apply-delta.sh <delta.sql.gz> <prefix> <statefile>

set -Eeuo pipefail

SQL_GZ="${1:?delta.sql.gz required}"
PREFIX="${2:?table prefix required}"
STATE="${3:?state file required}"

say() { printf '%s %s\n' "$(date -u +%H:%M:%S)" "$*" >> "$STATE"; }

WP_ROOT="${WP_ROOT:-$HOME/public_html}"
cd "$WP_ROOT" || { say "FATAL cannot enter ${WP_ROOT}"; echo FAILED >> "$STATE"; exit 1; }

n() { wp db query "$1" --skip-column-names --skip-plugins --skip-themes 2>/dev/null | tr -d '\r' | grep -E '^[0-9]+$' | head -1; }

say "START delta"

JOBAPPS="$(n "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='awsm_job_application';")"
COMMENTS="$(n "SELECT COUNT(*) FROM ${PREFIX}comments;")"
USERS="$(n "SELECT COUNT(*) FROM ${PREFIX}users;")"
say "baseline jobapps=${JOBAPPS:-0} comments=${COMMENTS:-0} users=${USERS:-0}"

say "applying"
if ! gzip -dc "$SQL_GZ" | wp db query --skip-plugins --skip-themes; then
  say "FATAL delta failed to apply — transaction rolled back, content unchanged"
  rm -f "$SQL_GZ" 2>/dev/null || true
  echo FAILED >> "$STATE"
  exit 1
fi
say "applied"

# ---------------------------------------------------------------------------
# The delta is computed with job applications excluded, so these must be
# untouched. Anything else means the exclusion failed and the snapshot should
# be used.
# ---------------------------------------------------------------------------
JA="$(n "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='awsm_job_application';")"
CM="$(n "SELECT COUNT(*) FROM ${PREFIX}comments;")"
US="$(n "SELECT COUNT(*) FROM ${PREFIX}users;")"
say "after jobapps=${JA:-0}/${JOBAPPS:-0} comments=${CM:-0}/${COMMENTS:-0} users=${US:-0}/${USERS:-0}"

if [[ "${JA:-0}" -ne "${JOBAPPS:-0}" || "${CM:-0}" -ne "${COMMENTS:-0}" || "${US:-0}" -ne "${USERS:-0}" ]]; then
  say "FATAL protected rows changed — restore with rollback-content.sh --yes"
  rm -f "$SQL_GZ" 2>/dev/null || true
  echo FAILED >> "$STATE"
  exit 1
fi

wp cache flush --skip-plugins --skip-themes >/dev/null 2>&1 || true
wp litespeed-purge all --skip-themes >/dev/null 2>&1 || true
wp elementor flush-css --skip-themes >/dev/null 2>&1 || true
say "caches flushed"

rm -f "$SQL_GZ" 2>/dev/null || true
say "SUCCESS"
echo DONE >> "$STATE"
