#!/usr/bin/env bash
#
# Exercises .github/check-version-bump.sh against throwaway git repositories.
#
# The cases that matter are the uncommitted and untracked ones. `git diff base...HEAD` alone
# reports neither, and a check written that way passes a branch whose shipping changes are
# simply not committed yet, which is the state a branch spends most of its life in.
#
# The src/ cases matter for a second reason: src/ is export-ignored and still ships, because
# build/ carries it compiled. Nothing else in this repository makes that distinction.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly SCRIPT_DIR
readonly CHECK="${SCRIPT_DIR}/.github/check-version-bump.sh"

pass=0
fail=0

check() {
	local label="$1" actual="$2" expected="$3"

	if [ "${actual}" = "${expected}" ]; then
		pass=$(( pass + 1 ))
		printf '  ok    %s\n' "${label}"
		return
	fi

	fail=$(( fail + 1 ))
	printf '  FAIL  %s\n        expected %s, got %s\n' "${label}" "${expected}" "${actual}"
}

work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

# Identity is passed per call, so the suite runs on a machine with no git user configured.
git_test() {
	git -C "$1" -c user.email=test@example.com -c user.name=Test "${@:2}"
}

# The check reads the version out of the plugin header, so that one line is the whole file
# these repositories need.
set_version() {
	printf ' * Version:           %s\n' "$2" > "$1/uat-checklist.php"
}

# inc/ ships and tests/ is export-ignored, so which of the two a change lands in is the
# question the check exists to answer.
change_shipping_file() {
	printf 'changed\n' >> "$1/inc/namespace.php"
}

# Prints the exit status rather than leaving it in $?, so each assertion is one self-contained
# line. The base defaults to main; the missing-base case passes its own.
run_check() {
	( cd "$1" && bash "${CHECK}" "${2:-main}" > /dev/null 2>&1 )
	printf '%d' "$?"
}

# A repository with main at 0.1.0 and a branch checked out, which is where a change lands.
# The .gitattributes mirrors the real one on the two facts under test: tests/ is ignored and
# does not ship, src/ is ignored and does.
make_repo() {
	local dir="${work}/$1"

	mkdir -p "${dir}/inc" "${dir}/src" "${dir}/tests"
	set_version "${dir}" '0.1.0'
	printf 'runtime\n' > "${dir}/inc/namespace.php"
	printf 'source\n' > "${dir}/src/index.js"
	printf 'suite\n' > "${dir}/tests/test-version.php"
	{
		printf '/tests export-ignore\n'
		printf '/src export-ignore\n'
		printf '/.github export-ignore\n'
		printf '/AGENTS.md export-ignore\n'
		printf '/.gitattributes export-ignore\n'
	} > "${dir}/.gitattributes"
	printf 'notes\n' > "${dir}/AGENTS.md"

	git_test "${dir}" init -q
	git_test "${dir}" checkout -q -b main
	git_test "${dir}" add -A
	git_test "${dir}" commit -q -m 'initial'
	git_test "${dir}" checkout -q -b feature

	printf '%s\n' "${dir}"
}

echo "A shipping change without a bump"
repo="$(make_repo unbumped)"
change_shipping_file "${repo}"
git_test "${repo}" add -A
git_test "${repo}" commit -q -m 'change runtime'
check 'is rejected' "$(run_check "${repo}")" '1'

echo
echo "The same change with a bump"
set_version "${repo}" '0.1.1'
git_test "${repo}" add -A
git_test "${repo}" commit -q -m 'bump'
check 'is accepted' "$(run_check "${repo}")" '0'

echo
echo "A branch that ships nothing"
repo="$(make_repo ignored)"
printf 'more\n' >> "${repo}/tests/test-version.php"
printf 'more\n' >> "${repo}/AGENTS.md"
git_test "${repo}" add -A
git_test "${repo}" commit -q -m 'tests and notes only'
check 'needs no bump' "$(run_check "${repo}")" '0'

echo
echo "src/ is export-ignored and still ships"
# It reaches the distributed plugin compiled into build/, so the export-ignore walk alone
# would read an editor rewrite as shipping nothing.
repo="$(make_repo compiled)"
printf 'edited\n' >> "${repo}/src/index.js"
check 'a modified source file is rejected' "$(run_check "${repo}")" '1'

set_version "${repo}" '0.1.1'
check 'and accepted once bumped' "$(run_check "${repo}")" '0'

# Nested, because the match is on the src/ prefix rather than on a direct child.
repo="$(make_repo compiled_new)"
mkdir -p "${repo}/src/extensions"
printf 'new module\n' > "${repo}/src/extensions/thing.js"
check 'a new source file is rejected' "$(run_check "${repo}")" '1'

echo
echo "Uncommitted work counts"
# The bug this file exists for: nothing is committed, so a base...HEAD diff sees an empty
# branch and reports success.
repo="$(make_repo uncommitted)"
change_shipping_file "${repo}"
check 'a modified shipping file is rejected' "$(run_check "${repo}")" '1'

set_version "${repo}" '0.1.1'
check 'and accepted once bumped, still uncommitted' "$(run_check "${repo}")" '0'

echo
echo "Untracked work counts"
repo="$(make_repo untracked)"
printf 'new module\n' > "${repo}/inc/new-thing.php"
check 'a new shipping file is rejected' "$(run_check "${repo}")" '1'

repo="$(make_repo untracked_ignored)"
printf 'new test\n' > "${repo}/tests/test-new.php"
check 'a new ignored file is not' "$(run_check "${repo}")" '0'

echo
echo "The version must go up, not merely differ"
repo="$(make_repo backwards)"
change_shipping_file "${repo}"
set_version "${repo}" '0.0.9'
check 'a lowered version is rejected' "$(run_check "${repo}")" '1'

set_version "${repo}" '0.2.0'
check 'a minor bump is accepted' "$(run_check "${repo}")" '0'

set_version "${repo}" '1.0.0'
check 'a major bump is accepted' "$(run_check "${repo}")" '0'

echo
echo "Escape hatch"
repo="$(make_repo hatch)"
change_shipping_file "${repo}"
check 'ALLOW_UNVERSIONED=1 passes a shipping change' "$(ALLOW_UNVERSIONED=1 run_check "${repo}")" '0'

echo
echo "An untouched branch"
repo="$(make_repo clean)"
check 'passes with nothing changed' "$(run_check "${repo}")" '0'

echo
echo "A missing base"
repo="$(make_repo nobase)"
check 'is an error, not a pass' "$(run_check "${repo}" nonexistent)" '1'

printf '\n%d passed, %d failed\n' "${pass}" "${fail}"
[ "${fail}" -eq 0 ]
