#!/usr/bin/env bash
# Lists, and with --delete removes, the drafts and auto-drafts the test administrator has left on the site.
#
#   clean-drafts.sh            # list only
#   clean-drafts.sh --delete   # delete what it lists
#
# screenshot.mjs deletes its scratch draft when it exits, so a shot that was stopped leaves one behind, and every
# new page an editor opens creates an auto-draft. Only uattestadmin's are touched: a real tester's draft, or one
# uattestbot saved during a check, is never listed. `post list --post_status=any` leaves auto-drafts out, which
# is why both statuses are named.
set -euo pipefail

# shellcheck source=config.sh
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

admin_id="$(wp user get "$ADMIN" --field=ID)"
drafts=(--post_type=page,post --post_status=draft,auto-draft --author="$admin_id")
ids="$(wp post list "${drafts[@]}" --field=ID | tr '\n' ' ')"

echo "$UAT_URL: ${ids:-none}"
wp post list "${drafts[@]}" --fields=ID,post_status,post_title,post_date --format=table 2> /dev/null | sed 's/^/  /'

if [ "${1:-}" = --delete ] && [ -n "${ids// /}" ]; then
	# shellcheck disable=SC2086 # the ids are separate arguments.
	wp post delete $ids --force | sed 's/^/  /'
fi
