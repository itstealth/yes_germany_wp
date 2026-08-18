#!/usr/bin/env bash
# Runs ON PRODUCTION. Applies a content push that has already been transferred.
#
# This is the whole critical section in one server-side script, launched
# detached. That is deliberate: earlier versions drove the import from an
# orchestrating machine over SSH, so a dropped connection stopped the work
# half-done and left WP-CLI processes holding table locks. Real job applications
# were lost that way.
#
# Nothing here depends on the network. Once launched, it completes or restores,
# with no operator attached.
#
# Usage (on production):  remote-apply.sh <sql.gz> <prefix> <statefile>

set -Eeuo pipefail

SQL_GZ="${1:?sql.gz required}"
PREFIX="${2:?table prefix required}"
STATE="${3:?state file required}"

say() { printf '%s %s\n' "$(date -u +%H:%M:%S)" "$*" >> "$STATE"; }

cd "$(dirname "$0")" 2>/dev/null || true
WP_ROOT="${WP_ROOT:-$HOME/public_html}"
cd "$WP_ROOT" || { say "FATAL cannot enter ${WP_ROOT}"; echo FAILED >> "$STATE"; exit 1; }

# The relaxed sql_mode travels with every statement because each wp db query is
# its own session. CREATE TABLE ... LIKE on a WordPress table fails under strict
# mode: posts.post_date defaults to '0000-00-00 00:00:00'. Production's mode
# happens to permit it today; this stops a server-side change from breaking the
# holding tables, which are what protect live job applications.
q() { wp db query "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'; $1" --skip-plugins --skip-themes 2>/dev/null; }
n() { wp db query "$1" --skip-column-names --skip-plugins --skip-themes 2>/dev/null | tr -d '\r' | grep -E '^[0-9]+$' | head -1; }

say "START"

# ---------------------------------------------------------------------------
# Baseline. Anything counted here must still be here at the end.
# ---------------------------------------------------------------------------
JOBAPPS="$(n "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='awsm_job_application';")"
COMMENTS="$(n "SELECT COUNT(*) FROM ${PREFIX}comments;")"
USERS="$(n "SELECT COUNT(*) FROM ${PREFIX}users;")"
say "baseline jobapps=${JOBAPPS:-0} comments=${COMMENTS:-0} users=${USERS:-0}"

# ---------------------------------------------------------------------------
# Restore-on-exit. Runs on success, error, or kill.
# ---------------------------------------------------------------------------
ARMED=false
finish() {
  local rc=$?
  if [[ "$ARMED" == "true" ]]; then
    say "restoring protected rows"
    q "INSERT IGNORE INTO ${PREFIX}posts    SELECT * FROM ${PREFIX}_hold_jobapps;
       INSERT IGNORE INTO ${PREFIX}postmeta SELECT * FROM ${PREFIX}_hold_jobmeta;
       INSERT IGNORE INTO ${PREFIX}comments    SELECT * FROM ${PREFIX}_hold_comments;
       INSERT IGNORE INTO ${PREFIX}commentmeta SELECT * FROM ${PREFIX}_hold_commentmeta;
       INSERT IGNORE INTO ${PREFIX}users    SELECT * FROM ${PREFIX}_hold_users;
       INSERT IGNORE INTO ${PREFIX}usermeta SELECT * FROM ${PREFIX}_hold_usermeta;" || true

    local ja cm us
    ja="$(n "SELECT COUNT(*) FROM ${PREFIX}posts WHERE post_type='awsm_job_application';")"
    cm="$(n "SELECT COUNT(*) FROM ${PREFIX}comments;")"
    us="$(n "SELECT COUNT(*) FROM ${PREFIX}users;")"
    say "after restore jobapps=${ja:-0}/${JOBAPPS:-0} comments=${cm:-0}/${COMMENTS:-0} users=${us:-0}/${USERS:-0}"

    if [[ "${ja:-0}" -ge "${JOBAPPS:-0}" && "${cm:-0}" -ge "${COMMENTS:-0}" && "${us:-0}" -ge "${USERS:-0}" ]]; then
      q "DROP TABLE IF EXISTS ${PREFIX}_hold_jobapps;    DROP TABLE IF EXISTS ${PREFIX}_hold_jobmeta;
         DROP TABLE IF EXISTS ${PREFIX}_hold_comments;   DROP TABLE IF EXISTS ${PREFIX}_hold_commentmeta;
         DROP TABLE IF EXISTS ${PREFIX}_hold_users;      DROP TABLE IF EXISTS ${PREFIX}_hold_usermeta;" || true
      say "protected rows verified, holds dropped"
    else
      say "WARNING protected rows incomplete — holds KEPT for recovery"
      rc=1
    fi
  fi
  rm -f "$SQL_GZ" 2>/dev/null || true
  [[ $rc -eq 0 ]] && echo "DONE" >> "$STATE" || echo "FAILED" >> "$STATE"
  exit $rc
}
trap finish EXIT

# ---------------------------------------------------------------------------
# Copy everything the push must not destroy into holding tables.
# ---------------------------------------------------------------------------
say "holding protected rows"
q "DROP TABLE IF EXISTS ${PREFIX}_hold_jobapps;  CREATE TABLE ${PREFIX}_hold_jobapps  LIKE ${PREFIX}posts;
   DROP TABLE IF EXISTS ${PREFIX}_hold_jobmeta;  CREATE TABLE ${PREFIX}_hold_jobmeta  LIKE ${PREFIX}postmeta;
   DROP TABLE IF EXISTS ${PREFIX}_hold_comments; CREATE TABLE ${PREFIX}_hold_comments LIKE ${PREFIX}comments;
   DROP TABLE IF EXISTS ${PREFIX}_hold_commentmeta; CREATE TABLE ${PREFIX}_hold_commentmeta LIKE ${PREFIX}commentmeta;
   DROP TABLE IF EXISTS ${PREFIX}_hold_users;    CREATE TABLE ${PREFIX}_hold_users    LIKE ${PREFIX}users;
   DROP TABLE IF EXISTS ${PREFIX}_hold_usermeta; CREATE TABLE ${PREFIX}_hold_usermeta LIKE ${PREFIX}usermeta;

   INSERT INTO ${PREFIX}_hold_jobapps SELECT * FROM ${PREFIX}posts WHERE post_type='awsm_job_application';
   INSERT INTO ${PREFIX}_hold_jobmeta SELECT pm.* FROM ${PREFIX}postmeta pm
     INNER JOIN ${PREFIX}posts p ON p.ID=pm.post_id WHERE p.post_type='awsm_job_application';
   INSERT INTO ${PREFIX}_hold_comments    SELECT * FROM ${PREFIX}comments;
   INSERT INTO ${PREFIX}_hold_commentmeta SELECT * FROM ${PREFIX}commentmeta;
   INSERT INTO ${PREFIX}_hold_users    SELECT * FROM ${PREFIX}users;
   INSERT INTO ${PREFIX}_hold_usermeta SELECT * FROM ${PREFIX}usermeta;" \
  || { say "FATAL could not hold protected rows"; exit 1; }

HELD="$(n "SELECT COUNT(*) FROM ${PREFIX}_hold_jobapps;")"
[[ "${HELD:-0}" -eq "${JOBAPPS:-0}" ]] || { say "FATAL held ${HELD:-0} of ${JOBAPPS:-0} job applications"; exit 1; }
say "held ok"

ARMED=true

# ---------------------------------------------------------------------------
# Apply. Local file, local database — no network in the loop.
# ---------------------------------------------------------------------------
say "importing"
gzip -dc "$SQL_GZ" | wp db query --skip-plugins --skip-themes || { say "FATAL import failed"; exit 1; }
say "imported"

# ---------------------------------------------------------------------------
# Caches, so the new content is what visitors get.
# ---------------------------------------------------------------------------
wp cache flush --skip-plugins --skip-themes >/dev/null 2>&1 || true
wp litespeed-purge all --skip-themes >/dev/null 2>&1 || true
wp elementor flush-css --skip-themes >/dev/null 2>&1 || true
say "caches flushed"

say "SUCCESS"
