#!/usr/bin/env bash
#
# Fails when a branch changes something that ships without raising the version.
#
# tests/test-version.php asserts the four version locations agree; it cannot notice that
# all four agree on the *old* number. Bumping nothing therefore passes every other check,
# and the mistake surfaces only when a release is cut (on a merged branch, by hand).
# See AGENTS.md: "Versioning".
#
# Usage: check-version-bump.sh [base-ref]        (default: main; CI passes origin/<base>)
# Set ALLOW_UNVERSIONED=1 to pass anyway, for a branch that deliberately ships unversioned.

set -euo pipefail

readonly BASE="${1:-main}"
readonly PLUGIN_FILE='uat-checklist.php'

# Roots whose contents reach the distributed plugin compiled rather than verbatim.
# .gitattributes export-ignores src/ and blocks/ because build/ is what the archive carries, so the
# export-ignore walk below would otherwise read an editor rewrite as shipping nothing.
# This is the one place the two lists are allowed to disagree, and the reason they do.
readonly COMPILED_ROOTS=( 'src' 'blocks' )

fail() {
	printf 'error: %s\n' "$1" >&2
	exit 1
}

read_version() {
	sed -n 's/^ \* Version: *//p' | tr -d '[:space:]'
}

# A path ships when it is compiled into the bundle, or when neither it nor any directory
# above it is export-ignored. The ancestor walk is the whole point: git archive prunes a
# directory before it looks inside, so `/tests` never matches tests/foo.php on its own.
# See AGENTS.md: "The release branch is stripped".
ships() {
	local candidate="$1"
	local root

	for root in "${COMPILED_ROOTS[@]}"; do
		case "${candidate}" in
			"${root}"/*) return 0 ;;
		esac
	done

	while [ "${candidate}" != '.' ] && [ "${candidate}" != '/' ]; do
		if [ "$( git check-attr export-ignore -- "${candidate}" | sed 's/.*: //' )" = 'set' ]; then
			return 1
		fi
		candidate="$( dirname "${candidate}" )"
	done

	return 0
}

version_gt() {
	local a1 a2 a3 b1 b2 b3
	IFS=. read -r a1 a2 a3 <<< "$1"
	IFS=. read -r b1 b2 b3 <<< "$2"

	if [ "$(( a1 ))" -ne "$(( b1 ))" ]; then
		[ "$(( a1 ))" -gt "$(( b1 ))" ]
		return
	fi
	if [ "$(( a2 ))" -ne "$(( b2 ))" ]; then
		[ "$(( a2 ))" -gt "$(( b2 ))" ]
		return
	fi
	[ "$(( a3 ))" -gt "$(( b3 ))" ]
}

git rev-parse --verify --quiet "${BASE}" > /dev/null \
	|| fail "base ref '${BASE}' not found. CI needs actions/checkout with fetch-depth: 0"

base_version="$( git show "${BASE}:${PLUGIN_FILE}" | read_version )"
head_version="$( read_version < "${PLUGIN_FILE}" )"

[ -n "${base_version}" ] || fail "no Version header on ${BASE}"
[ -n "${head_version}" ] || fail "no Version header in ${PLUGIN_FILE}"

# All three sources, because a branch mid-review has committed, modified and untracked work
# at once, and `git diff ${BASE}...HEAD` alone reports none of the last two. Reading only
# that is how this check would come to pass a branch it should fail.
changed="$( mktemp )"
shipping="$( mktemp )"
trap 'rm -f "${changed}" "${shipping}"' EXIT

{
	git diff --name-only "${BASE}...HEAD"
	git diff --name-only HEAD
	git ls-files --others --exclude-standard
} | LC_ALL=C sort -u > "${changed}"

while IFS= read -r path; do
	[ -n "${path}" ] || continue
	if ships "${path}"; then
		printf '%s\n' "${path}" >> "${shipping}"
	fi
done < "${changed}"

if [ ! -s "${shipping}" ]; then
	printf 'No change ships: nothing to version (still %s).\n' "${head_version}"
	exit 0
fi

shipping_count="$( wc -l < "${shipping}" | tr -d ' ' )"

if [ "${ALLOW_UNVERSIONED:-0}" = '1' ]; then
	printf 'ALLOW_UNVERSIONED=1: %d shipping change(s) accepted at %s.\n' \
		"${shipping_count}" "${head_version}"
	exit 0
fi

if [ "${head_version}" = "${base_version}" ]; then
	printf 'error: these changes ship, but the version is still %s:\n' "${head_version}" >&2
	sed 's/^/  /' "${shipping}" >&2
	printf 'Bump all four locations. See AGENTS.md: "Versioning".\n' >&2
	exit 1
fi

version_gt "${head_version}" "${base_version}" \
	|| fail "version went backwards: ${BASE} is ${base_version}, this branch is ${head_version}"

printf 'Version raised %s -> %s for %d shipping change(s).\n' \
	"${base_version}" "${head_version}" "${shipping_count}"
