#!/usr/bin/env bash
# Sourced by the shell scripts: the same UAT_URL, UAT_WP and UAT_CACHE that config.py documents and lib.mjs reads.

if [ -z "${UAT_URL:-}" ]; then
	echo "Set UAT_URL to the site's home URL, for example UAT_URL=http://localhost:8888/" >&2
	exit 1
fi

UAT_URL="${UAT_URL%/}/"
SITE_KEY="$(printf '%s' "${UAT_URL#*://}" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//')"
CACHE="${UAT_CACHE:-$HOME/.cache/hm-uat/$SITE_KEY}"
CREDENTIALS="$CACHE/credentials.json"
TESTER=uattestbot
ADMIN=uattestadmin

# Runs WP-CLI against the site through the UAT_WP command, split on spaces.
wp() {
	local -a prefix
	read -ra prefix <<< "${UAT_WP:-wp}"
	"${prefix[@]}" --url="$UAT_URL" "$@"
}
