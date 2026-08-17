#!/usr/bin/env bash
# Make a freshly-imported staging copy safe to work in.
#
# Staging starts as a byte-for-byte copy of production, which means it also
# inherits production's credentials and connections to live third-party
# accounts. Left alone, staging will happily send conversions to the client's
# real ad accounts, submit its own URLs to search engines, and spend the
# client's API credits.
#
# Everything disabled here is listed in STAGING_DISABLED_PLUGINS. That list is
# read by the content push, so these stay ACTIVE on production — otherwise
# pushing staging's plugin state would switch off the client's live tracking.
#
# Safe to re-run. Refuses to run against production.
#
# Usage: harden-staging.sh

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

configure_environment "staging"

ENV_TYPE="$(wp_remote config get WP_ENVIRONMENT_TYPE --skip-plugins --skip-themes 2>/dev/null | tr -d '\r\n' || echo unset)"
[[ "$ENV_TYPE" == "staging" ]] || die "Remote reports '${ENV_TYPE}'. This script only runs against staging."
ok "confirmed target is staging"

# ---------------------------------------------------------------------------
# Plugins that reach out to the client's live accounts.
# Each entry says what it would do if left enabled.
# ---------------------------------------------------------------------------
# shellcheck disable=SC2034
STAGING_DISABLED_PLUGINS=(
  "google-site-kit"                                    # holds production's Google OAuth tokens
  "google-analytics-for-wordpress"                     # sends pageviews to the live GA property
  "microsoft-advertising-universal-event-tracking-uet"  # fires conversions at the live Microsoft Ads account
  "aioseo-index-now"                                   # submits URLs to IndexNow/Bing
  "optinmonster"                                       # serves live campaigns, collects real emails
  "broken-link-checker-seo"                            # reports this site's URLs to the AIOSEO cloud
  "litespeed-cache"                                    # needs the LiteSpeed server; costs 88s/request here
  # Both of these inject a SECOND set of tracking tags straight from the
  # database, bypassing the theme's environment gating entirely:
  #   GTM-PM6GKCL, GA4 G-SN15QKRPMJ, Google Ads AW-619987764
  # which are different IDs from the ones the theme carries.
  "insert-headers-and-footers"
  "wp-headers-and-footers"
)

log "Disabling plugins that would talk to production services"
for p in "${STAGING_DISABLED_PLUGINS[@]}"; do
  if wp_remote plugin is-active "$p" --skip-themes >/dev/null 2>&1; then
    wp_remote plugin deactivate "$p" --skip-themes >/dev/null 2>&1 \
      && ok "deactivated ${p}" || warn "could not deactivate ${p}"
  else
    ok "already inactive: ${p}"
  fi
done

# ---------------------------------------------------------------------------
# Strip inherited credentials.
#
# These belong to the production domain. Google OAuth tokens in particular are
# domain-scoped, so leaving them here lets staging write to the client's real
# Analytics and Search Console properties.
# ---------------------------------------------------------------------------
log "Removing inherited credentials"
CREDENTIAL_OPTIONS=(
  googlesitekit_credentials
  googlesitekit_access_token
  googlesitekit_refresh_token
  googlesitekit_search_console_property
  googlesitekit_analytics_settings
  googlesitekit_analytics-4_settings
  monsterinsights_oauth
  optinmonster_api
  pushalert_api_key
)
for o in "${CREDENTIAL_OPTIONS[@]}"; do
  wp_remote option delete "$o" --skip-plugins --skip-themes >/dev/null 2>&1 \
    && ok "removed ${o}" || true
done

# ---------------------------------------------------------------------------
# Keep search engines out, at the data layer as well as the header.
# ---------------------------------------------------------------------------
# SQL, not `wp option update`: the mu-plugin filters pre_option_blog_public to
# 0, so update_option() sees no change and never writes.
PREFIX_H="$(wp_remote config get table_prefix --skip-plugins --skip-themes 2>/dev/null | tr -d '\r\n')"
db_query "UPDATE ${PREFIX_H}options SET option_value='0' WHERE option_name='blog_public';" >/dev/null 2>&1 \
  && ok "blog_public = 0"

# Clear cron events inherited from production so nothing fires on a schedule.
wp_remote cron event delete --all >/dev/null 2>&1 && ok "inherited cron events cleared" || true

# ---------------------------------------------------------------------------
# Report anything still holding a credential, so nothing is missed silently.
# ---------------------------------------------------------------------------
log "Remaining credential-shaped options (review these)"
PREFIX="$(wp_remote config get table_prefix --skip-plugins --skip-themes 2>/dev/null | tr -d '\r\n')"
db_query "SELECT option_name FROM ${PREFIX}options
          WHERE (option_name LIKE '%license%' OR option_name LIKE '%api_key%'
              OR option_name LIKE '%apikey%'  OR option_name LIKE '%oauth%'
              OR option_name LIKE '%credential%' OR option_name LIKE '%_token%')
            AND option_name NOT LIKE '_transient%'
            AND option_name NOT LIKE '_site_transient%'
          ORDER BY option_name;" 2>/dev/null | sed 's/^/    /'

echo
log "Staging hardened."
warn "These plugins are OFF on staging by design and must stay ON in production."
warn "push-content.sh reads STAGING_DISABLED_PLUGINS and preserves their live state."
