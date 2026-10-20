#!/usr/bin/env bash
# Creates the two UAT test users if they are missing, records their passwords, and gives both full access to UAT
# checklists.
#
#   uattestbot    Editor: verify-page.mjs signs in as it, so checks never touch a real tester's results.
#   uattestadmin  Administrator: screenshot.mjs signs in as it, for the Site Editor.
#
# Passwords live in <cache>/credentials.json, readable only by you. A user that exists without a recorded
# password is given a new one. Local environments only, unless UAT_ALLOW_REMOTE=1: these are accounts with known
# passwords, and an administrator among them.
set -euo pipefail

# shellcheck source=config.sh
source "$(dirname "${BASH_SOURCE[0]}")/config.sh"

host="${UAT_URL#*://}"
host="${host%%/*}"
host="${host%%:*}"

case "$host" in
	localhost | 127.0.0.1 | *.localhost | *.test | *.local | *.ddev.site | *.lndo.site | *.wp.local) ;;
	*)
		if [ "${UAT_ALLOW_REMOTE:-0}" != 1 ]; then
			echo "Refusing: $host does not look like a local environment. Set UAT_ALLOW_REMOTE=1 to create the users anyway." >&2
			exit 1
		fi
		;;
esac

mkdir -p "$CACHE"
umask 077
[ -f "$CREDENTIALS" ] || echo '{}' > "$CREDENTIALS"

for pair in "$TESTER:editor" "$ADMIN:administrator"; do
	user="${pair%%:*}"
	role="${pair##*:}"

	if [ -n "$(jq -r --arg u "$user" '.[$u] // empty' "$CREDENTIALS")" ] \
		&& wp user get "$user" --field=ID > /dev/null 2>&1; then
		echo "$user: ready"
		continue
	fi

	password="$(openssl rand -base64 24 | tr -d '/+=')"

	if wp user get "$user" --field=ID > /dev/null 2>&1; then
		wp user update "$user" --user_pass="$password" --skip-email > /dev/null
		wp user set-role "$user" "$role" > /dev/null
		echo "$user: existed, password reset"
	else
		wp user create "$user" "$user@example.invalid" \
			--role="$role" --user_pass="$password" --display_name="UAT $role (test)" > /dev/null
		echo "$user: created as $role"
	fi

	jq --arg u "$user" --arg p "$password" '.[$u] = $p' "$CREDENTIALS" > "$CREDENTIALS.tmp"
	mv "$CREDENTIALS.tmp" "$CREDENTIALS"
done

# Both open checklists in the editor, which only a UAT manager may do: the plugin grants that by email domain, or
# by this user meta, which the profile checkbox "Full access to UAT checklists" sets. Safe to set on every run.
for user in "$TESTER" "$ADMIN"; do
	wp user meta update "$user" hm_uat_checklist_full_access 1 > /dev/null
done
