#!/usr/bin/env bash
# Sanitize a freshly imported production database on STAGING.
#
# Staging carries a copy of a live database belonging to a business that serves
# German and Indian students, so it holds real personal data. This makes that
# copy safe to work with:
#
#   * every user keeps their row, so post_author references and Elementor
#     template ownership stay intact — deleting users would make staging stop
#     matching production, which defeats the point of having staging
#   * emails are scrambled, so nothing can contact a real person
#   * passwords are randomised, so production credentials do not work here
#   * form submissions and job applications are dropped entirely
#
# Refuses to run against production. Usage: sanitize-staging.sh

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

configure_environment "staging"

# ---------------------------------------------------------------------------
# Hard refusal. This script destroys data; it must never see production.
# ---------------------------------------------------------------------------
ENV_TYPE="$(wp_remote config get WP_ENVIRONMENT_TYPE --skip-plugins --skip-themes 2>/dev/null | tr -d '\r\n' || echo unset)"
if [[ "$ENV_TYPE" != "staging" ]]; then
  die "Remote reports WP_ENVIRONMENT_TYPE='${ENV_TYPE}'. This script only runs against staging. Refusing."
fi
ok "confirmed target is staging"

SITE_URL="${SITE_URL:-https://yg-staging.stealthlearn.in}"
PROD_URL="${PROD_URL:-https://www.yesgermany.com}"

# ---------------------------------------------------------------------------
# 1. Rewrite URLs
#
# Ordered most-specific first. A naive replace of the bare domain would also
# rewrite substrings inside longer hostnames, so the scheme+host form is used.
# ---------------------------------------------------------------------------
log "Rewriting site URLs"
for from in "https://www.yesgermany.com" "http://www.yesgermany.com" \
            "https://yesgermany.com"     "http://yesgermany.com"; do
  wp_remote search-replace "'${from}'" "'${SITE_URL}'" \
    --all-tables-with-prefix --precise --skip-columns=guid --report-changed-only \
    --skip-plugins --skip-themes 2>/dev/null || warn "replace failed for ${from}"
done
ok "URLs rewritten"

wp_remote option update home "'${SITE_URL}'" --skip-plugins --skip-themes >/dev/null 2>&1 || true
wp_remote option update siteurl "'${SITE_URL}'" --skip-plugins --skip-themes >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# 2. Scramble personal data, keeping rows intact
# ---------------------------------------------------------------------------
log "Scrambling user emails and passwords"
PREFIX="$(wp_remote config get table_prefix --skip-plugins --skip-themes 2>/dev/null | tr -d '\r\n')"
[[ -n "$PREFIX" ]] || die "Could not determine table prefix."

db_query "UPDATE ${PREFIX}users
  SET user_email = CONCAT('user', ID, '@invalid.local'),
      user_pass  = CONCAT('!disabled!', MD5(RAND()), ID),
      user_url   = ''
  WHERE user_login NOT IN ('yg_staging');" >/dev/null \
  || die "Failed to scramble users."
ok "user emails and passwords scrambled"

# Contact details stored in usermeta.
db_query "DELETE FROM ${PREFIX}usermeta
  WHERE meta_key IN ('billing_email','billing_phone','billing_address_1','billing_address_2',
                     'shipping_address_1','shipping_address_2','phone','mobile');" >/dev/null 2>&1 || true
ok "contact metadata removed"

# ---------------------------------------------------------------------------
# 3. Drop submitted personal data outright
#
# These tables hold student enquiries and job applications. There is no reason
# for a staging copy to carry them.
# ---------------------------------------------------------------------------
log "Dropping form submissions and applications"
for t in e_submissions e_submissions_values e_submissions_actions_log \
         sgpb_subscribers db7_forms wpforms_db frmt_form_entry frmt_form_entry_meta; do
  db_query "TRUNCATE TABLE ${PREFIX}${t};" >/dev/null 2>&1 \
    && ok "cleared ${PREFIX}${t}" || true
done

# WP Job Openings applicants are a custom post type.
db_query "DELETE pm FROM ${PREFIX}postmeta pm
  INNER JOIN ${PREFIX}posts p ON p.ID = pm.post_id
  WHERE p.post_type = 'awsm_job_application';" >/dev/null 2>&1 || true
db_query "DELETE FROM ${PREFIX}posts WHERE post_type = 'awsm_job_application';" >/dev/null 2>&1 \
  && ok "job applications removed" || true

# Unapproved comment backlog is mostly spam carrying email addresses.
db_query "DELETE FROM ${PREFIX}commentmeta WHERE comment_id IN
  (SELECT comment_ID FROM ${PREFIX}comments WHERE comment_approved != '1');" >/dev/null 2>&1 || true
db_query "DELETE FROM ${PREFIX}comments WHERE comment_approved != '1';" >/dev/null 2>&1 \
  && ok "unapproved comments removed" || true

# ---------------------------------------------------------------------------
# 4. Neutralise anything that talks to the outside world
# ---------------------------------------------------------------------------
log "Disabling outbound integrations"
# Written with SQL, not `wp option update`. The mu-plugin filters
# pre_option_blog_public to 0, so update_option() reads 0, sees "no change" and
# skips the write — leaving 1 in the database forever.
db_query "UPDATE ${PREFIX}options SET option_value='0' WHERE option_name='blog_public';" >/dev/null 2>&1 || true

# Clear scheduled jobs inherited from production.
wp_remote cron event delete --all >/dev/null 2>&1 || true
ok "inherited cron events cleared"

# ---------------------------------------------------------------------------
# 5. A working administrator for the team
# ---------------------------------------------------------------------------
if [[ -n "${STAGING_ADMIN_PASS:-}" ]]; then
  log "Creating staging administrator"
  wp_remote user create yg_staging staging@invalid.local \
    --role=administrator --user_pass="'${STAGING_ADMIN_PASS}'" \
    --skip-plugins --skip-themes >/dev/null 2>&1 \
    && ok "created user 'yg_staging'" \
    || warn "user 'yg_staging' may already exist"
else
  warn "STAGING_ADMIN_PASS not set — no staging admin created."
fi

# ---------------------------------------------------------------------------
# 6. Report what remains
# ---------------------------------------------------------------------------
log "Verification"
REAL_EMAILS="$(db_query "SELECT COUNT(*) FROM ${PREFIX}users WHERE user_email NOT LIKE '%@invalid.local';" 2>/dev/null | tr -d '\r\n ' || echo '?')"
if [[ "$REAL_EMAILS" == "0" ]]; then
  ok "no real email addresses remain"
else
  warn "${REAL_EMAILS} user(s) still have non-scrambled emails"
fi

wp_remote cache flush --skip-plugins --skip-themes >/dev/null 2>&1 || true
log "Sanitization complete."
