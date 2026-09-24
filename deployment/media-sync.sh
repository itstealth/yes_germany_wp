#!/usr/bin/env bash
# Runs ON STAGING. Copies media files that exist on staging but not on
# production, so a published page never points at an image live does not have.
#
# The content push carries attachment rows, not the files behind them. Before
# this, an image uploaded on staging reached live as a broken link: the row said
# the file existed, the disk said otherwise.
#
# Production is a cPanel host with no rsync, so the comparison is two sorted file
# listings and the transfer is a tar stream of just the difference.
#
# Only ever ADDS files. Nothing on production is overwritten or deleted.
#
# Only the dated media folders (uploads/2020/..., uploads/2026/...) and only
# media and document types travel. The uploads root on both servers holds plugin
# caches, zips and stray .php files, none of which belong in a publish.
#
# Usage:
#   media-sync.sh plan <staging-uploads> <prod-user@host> <prod-uploads> <list-file>
#       Writes the files production lacks to <list-file>, then prints
#       "MEDIA files=N bytes=N" and one "FILE <path>" line per file (first 40).
#   media-sync.sh copy <staging-uploads> <prod-user@host> <prod-uploads> <list-file>
#       Sends every file named in <list-file>, then checks each one arrived at
#       the same size. Prints "MEDIA copied=N failed=N".

set -Eeuo pipefail

MODE="${1:?mode required: plan or copy}"
SRC="${2:?staging uploads dir required}"
DEST_HOST="${3:?production user@host required}"
DEST="${4:?production uploads dir required}"
LIST="${5:?list file required}"

# The same key and options every other staging -> production step uses.
SSH=(ssh -i "${HOME}/.ssh/prod_deploy_key" -o AddressFamily=inet -o BatchMode=yes
     -o StrictHostKeyChecking=yes -o ConnectTimeout=20 -o ServerAliveInterval=15)

TYPES='jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|pdf|mp4|m4v|webm|mov|mp3|m4a|wav|ogg|docx?|xlsx?|pptx?|csv'
FIND="find 20[0-9][0-9] -regextype posix-extended -type f -iregex '.*\\.(${TYPES})'"

cd "$SRC"

# Size and path of each listed file, "missing" when absent. Run on both sides and
# compared line for line, so a truncated transfer counts as a failure.
SIZES='while IFS= read -r f; do printf "%s\t%s\n" "$(stat -c %s -- "$f" 2>/dev/null || echo missing)" "$f"; done'

case "$MODE" in
  plan)
    eval "$FIND" | LC_ALL=C sort > "${LIST}.stg"
    # pipefail makes a failed ssh fail this line, rather than leaving an empty
    # listing that would make every staging file look missing.
    "${SSH[@]}" "$DEST_HOST" "cd '${DEST}' && ${FIND}" | LC_ALL=C sort > "${LIST}.prod"

    # Production holds ~64k media files. A listing far short of that means the
    # command failed or ran in the wrong place, and trusting it would try to
    # re-send the whole library.
    N_PROD="$(wc -l < "${LIST}.prod")"
    if [[ "$N_PROD" -lt 1000 ]]; then
      rm -f "${LIST}.stg" "${LIST}.prod"
      echo "MEDIA error: production listed only ${N_PROD} files under ${DEST}" >&2
      exit 1
    fi

    LC_ALL=C comm -23 "${LIST}.stg" "${LIST}.prod" > "$LIST"
    rm -f "${LIST}.stg" "${LIST}.prod"

    N="$(wc -l < "$LIST")"
    BYTES=0
    if [[ "$N" -gt 0 ]]; then
      BYTES="$(tr '\n' '\0' < "$LIST" | du -cb --files0-from=- | tail -1 | cut -f1)"
    fi
    echo "MEDIA files=${N} bytes=${BYTES}"
    head -n 40 "$LIST" | sed 's/^/FILE /'
    ;;

  copy)
    if [[ ! -s "$LIST" ]]; then
      echo "MEDIA copied=0 failed=0"
      exit 0
    fi

    # -k keeps any file production already has. The list holds only files it
    # lacked, so this matters only if one appeared in the meantime; tar then
    # reports it and exits non-zero, which is fine because the check below, not
    # tar's exit code, decides whether the copy worked.
    tar czf - -T "$LIST" \
      | "${SSH[@]}" "$DEST_HOST" "cd '${DEST}' && tar xzkf - --no-same-owner" || true

    bash -c "$SIZES" < "$LIST" > "${LIST}.want"
    "${SSH[@]}" "$DEST_HOST" "cd '${DEST}' && ${SIZES}" < "$LIST" > "${LIST}.got" || true

    N="$(wc -l < "$LIST")"
    FAILED="$(LC_ALL=C comm -23 <(LC_ALL=C sort "${LIST}.want") <(LC_ALL=C sort "${LIST}.got") | wc -l)"
    LC_ALL=C comm -23 <(LC_ALL=C sort "${LIST}.want") <(LC_ALL=C sort "${LIST}.got") \
      | head -n 20 | sed 's/^/FAILED /'
    rm -f "${LIST}.want" "${LIST}.got"

    echo "MEDIA copied=$(( N - FAILED )) failed=${FAILED}"
    [[ "$FAILED" -eq 0 ]]
    ;;

  *)
    echo "unknown mode '${MODE}' (expected plan or copy)" >&2
    exit 2
    ;;
esac
