#!/usr/bin/env bash
# Check every moving part a publish depends on, before anyone approves one.
#
# This exists because the same publish broke four times in a week for four
# different reasons — the host migrated the server without telling us, shell
# access did not survive the migration, wp-cli did not survive it either, and
# staging still pinned the dead server's host key. Each one surfaced only when a
# publish was already half-run, and each cost hours to identify from a log that
# said "scp: Connection closed".
#
# None of those are preventable from here. All of them are *nameable* in ten
# seconds, which is what this does. Every check prints what to do about it.
#
# Runs before the approval gate. Read-only: it writes nothing anywhere.
#
# Usage: preflight.sh

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

STG_HOST="${STAGING_HOST:-100.66.61.11}"
STG_USER="${STAGING_USER:-ygdeploy}"
STG_PORT="${STAGING_PORT:-2222}"
STG_DIR="${STAGING_STACK_DIR:-/opt/yesgermany/staging}"

PROD_HOST="${PROD_HOST:?PROD_HOST required}"
PROD_USER="${PROD_USER:?PROD_USER required}"
PROD_ROOT="${PROD_ROOT:?PROD_ROOT required}"
PREFIX="${TABLE_PREFIX:-wpb9_}"

STG_KEY="${STAGING_SSH_KEY_FILE:-${HOME}/.ssh/deploy_key}"
PROD_KEY="${PROD_SSH_KEY_FILE:-${HOME}/.ssh/prod_deploy_key}"
SSHB="-o AddressFamily=inet -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=20 -o ServerAliveInterval=15"

# Multiplex: the cPanel host rate-limits new connections and this opens several.
CM_DIR="${TMPDIR:-/tmp}/yg-pf-$$"
mkdir -p "$CM_DIR" && chmod 700 "$CM_DIR"
SSHB="${SSHB} -o ControlMaster=auto -o ControlPath=${CM_DIR}/%C -o ControlPersist=60"
cleanup_pf() {
  for s in "$CM_DIR"/*; do [[ -S "$s" ]] && ssh -O exit -o ControlPath="$s" x 2>/dev/null || true; done
  rm -rf "$CM_DIR" 2>/dev/null || true
}
trap cleanup_pf EXIT

# shellcheck disable=SC2086
stg()  { ssh -n -i "$STG_KEY"  -p "$STG_PORT" $SSHB "${STG_USER}@${STG_HOST}" "$@"; }
# shellcheck disable=SC2086
prod() { ssh -n -i "$PROD_KEY" $SSHB "${PROD_USER}@${PROD_HOST}" "$@"; }

FAILED=0
# Collected rather than fatal: one run should name every broken thing, not make
# you fix them one publish at a time.
bad()  { printf '\033[0;31m  ✗\033[0m %s\n' "$1"; [[ -n "${2:-}" ]] && printf '      → %s\n' "$2"; FAILED=$((FAILED+1)); }

log "Preflight for a publish to ${PROD_HOST}"

# ---------------------------------------------------------------------------
# 1. DNS. PROD_HOST is a hostname now, not a literal, so it can resolve to an
#    address CI cannot route to. It resolved to IPv6 once and the health check
#    died with "Network is unreachable" after a publish that had succeeded.
# ---------------------------------------------------------------------------
if [[ "$PROD_HOST" =~ ^[0-9.]+$ ]]; then
  ok "production host is a literal IPv4 address"
else
  A="$(getent ahostsv4 "$PROD_HOST" 2>/dev/null | awk '{print $1}' | sort -u | head -1 || true)"
  if [[ -n "$A" ]]; then
    ok "production hostname resolves to ${A} (IPv4)"
  else
    bad "production hostname ${PROD_HOST} has no IPv4 address" \
        "Ask the host for an A record, or put the IPv4 address in the PROD_HOST secret."
  fi
fi

# ---------------------------------------------------------------------------
# 2. Production SSH. Authenticating and having a shell are different things:
#    a disabled cPanel account authenticates fine and then refuses to run
#    anything, which reads like an auth failure and gets tickets bounced back.
# ---------------------------------------------------------------------------
SSH_OUT="$(prod 'echo __YG_SHELL_OK__' 2>&1 || true)"
if printf '%s' "$SSH_OUT" | grep -q '__YG_SHELL_OK__'; then
  ok "production SSH: key accepted and shell runs"
elif printf '%s' "$SSH_OUT" | grep -qi 'shell access is not enabled'; then
  bad "production SSH: key accepted, but the account has no shell" \
      "Ask the host to re-enable Jailed Shell for ${PROD_USER}. The key is fine — do not report this as an auth failure."
elif printf '%s' "$SSH_OUT" | grep -qi 'host key verification failed'; then
  bad "production SSH: host key does not match PROD_KNOWN_HOSTS" \
      "The server was probably rebuilt or migrated. Refresh the PROD_KNOWN_HOSTS secret."
elif printf '%s' "$SSH_OUT" | grep -qi 'permission denied'; then
  bad "production SSH: key rejected" \
      "PROD_SSH_KEY is not in the account's authorized keys (cPanel → SSH Access → Manage SSH Keys)."
else
  bad "production SSH: cannot connect — ${SSH_OUT:-no output}" \
      "Host may have moved. Check the address in PROD_HOST still answers on port 22."
fi

# Everything below needs a working shell; without one they all fail identically
# and bury the one message that matters.
if [[ "$FAILED" -eq 0 ]]; then
  # -------------------------------------------------------------------------
  # 3. wp-cli. Installed per-account at ~/bin/wp, so a server rebuild loses it.
  # -------------------------------------------------------------------------
  if prod 'command -v wp >/dev/null 2>&1'; then
    ok "production wp-cli: $(prod 'wp --version 2>/dev/null' | head -1)"
  else
    bad "production has no wp-cli on PATH" \
        "Reinstall it: curl -o ~/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x ~/bin/wp"
  fi

  # -------------------------------------------------------------------------
  # 4. WordPress and its database.
  # -------------------------------------------------------------------------
  WPV="$(prod "cd '${PROD_ROOT}' && wp core version --skip-plugins --skip-themes 2>/dev/null" | tr -d '\r' | head -1 || true)"
  if [[ -n "$WPV" ]]; then
    ok "production WordPress ${WPV} at ${PROD_ROOT}"
  else
    bad "cannot read WordPress at ${PROD_ROOT}" "Check PROD_ROOT points at the document root holding wp-config.php."
  fi

  ROWS="$(prod "cd '${PROD_ROOT}' && wp db query 'SELECT COUNT(*) FROM ${PREFIX}posts;' --skip-column-names --skip-plugins --skip-themes 2>/dev/null" | tr -dc '0-9' || true)"
  if [[ "${ROWS:-0}" -gt 0 ]]; then
    ok "production database reachable (${ROWS} posts)"
  else
    bad "production database not reachable" "Check the credentials in wp-config.php and that the DB server is up."
  fi

  # -------------------------------------------------------------------------
  # 5. Disk. A publish writes a snapshot and a dump; a full disk during the
  #    apply leaves the content tables half-written. Cheaper to refuse here.
  # -------------------------------------------------------------------------
  AVAIL_MB="$(prod "df -Pm '${PROD_ROOT}' 2>/dev/null | awk 'NR==2{print \$4}'" | tr -dc '0-9' || true)"
  if [[ -z "${AVAIL_MB:-}" ]]; then
    warn "could not read free disk on production (CageFS hides df) — check quota in cPanel"
  elif [[ "$AVAIL_MB" -lt 2048 ]]; then
    bad "only ${AVAIL_MB} MB free on production" \
        "A publish needs headroom for a snapshot and a dump. Free space or ask the host to extend storage."
  elif [[ "$AVAIL_MB" -lt 5120 ]]; then
    warn "production has ${AVAIL_MB} MB free — thin. Publishes will keep working but plan for more space."
  else
    ok "production disk: ${AVAIL_MB} MB free"
  fi
fi

# ---------------------------------------------------------------------------
# 6. Staging, and staging's own route to production.
#
#    The transfers run FROM staging, against staging's own known_hosts — not
#    the runner's. Updating PROD_KNOWN_HOSTS alone does not help, which is how a
#    publish got all the way to "Push content" and died with "Connection
#    closed" after the server moved.
# ---------------------------------------------------------------------------
if stg 'echo ok' >/dev/null 2>&1; then
  ok "staging SSH reachable at ${STG_USER}@${STG_HOST}:${STG_PORT}"

  if stg "test -d '${STG_DIR}'"; then
    ok "staging stack present at ${STG_DIR}"
  else
    bad "staging stack missing at ${STG_DIR}" "Set STAGING_STACK_DIR, or check the stack was not moved."
  fi

  S2P="$(stg "ssh -n -i ~/.ssh/prod_deploy_key -o AddressFamily=inet -o BatchMode=yes \
              -o StrictHostKeyChecking=yes -o ConnectTimeout=20 \
              '${PROD_USER}@${PROD_HOST}' 'echo __YG_S2P_OK__' 2>&1" || true)"
  if printf '%s' "$S2P" | grep -q '__YG_S2P_OK__'; then
    ok "staging can reach production directly"
  elif printf '%s' "$S2P" | grep -qi 'host key verification failed\|no matching host key\|known_hosts'; then
    bad "staging does not trust production's host key" \
        "Add production's keys to ${STG_USER}@${STG_HOST}:~/.ssh/known_hosts. The workflow's 'Trust production from staging' step normally does this."
  else
    bad "staging cannot reach production — ${S2P:-no output}" \
        "The publish transfers run from staging, so this must work before a publish can finish."
  fi
else
  bad "staging SSH unreachable at ${STG_USER}@${STG_HOST}:${STG_PORT}" \
      "Staging is only reachable over Tailscale — check the tailnet connection and the ACL for tag:ci."
fi

echo
if [[ "$FAILED" -gt 0 ]]; then
  die "Preflight found ${FAILED} problem(s). Nothing has been published."
fi
ok "Preflight passed — safe to publish"
