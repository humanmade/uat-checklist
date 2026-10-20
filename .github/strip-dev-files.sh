#!/usr/bin/env bash
#
# Strip development files from the release branch commit.
#
# Runs as the build step of humanmade/hm-github-actions' build-to-release-branch action, between
# the reverse-applied merge (which leaves the index holding all of main's tree) and the
# `git commit --amend` that publishes it. Dropping paths from the index keeps them out of the
# release commit without touching the working tree, and the next run re-applies main in full
# before this script runs again, so it is idempotent.
#
# The compiled bundle is added by the action's own `git add -f`, which runs after this, so
# build/ is not in the index here and cannot be stripped by it.

set -euo pipefail

# What to strip is decided by `git archive`, never by re-reading .gitattributes with check-attr:
# an export-ignore on a directory pattern such as `/tests` matches the directory entry alone, so
# check-attr reports nothing for the files inside it and the whole suite would ship.
# See AGENTS.md: "The release branch is stripped, and `git archive` is what decides by how much".

# The merge has staged main's tree but not committed it, so HEAD is still the previous
# release commit. write-tree turns the index into a tree object archive can read.
tree="$( git write-tree )"

keep="$( mktemp )"
tracked="$( mktemp )"
strip="$( mktemp )"
trap 'rm -f "${keep}" "${tracked}" "${strip}"' EXIT

# Directory entries carry a trailing slash and are not index paths, so they cannot match
# a git ls-files line and would otherwise be counted as strippable.
git archive --format=tar "${tree}" | tar -tf - | grep -v '/$' | LC_ALL=C sort > "${keep}"
git ls-files | LC_ALL=C sort > "${tracked}"
comm -23 "${tracked}" "${keep}" > "${strip}"

if [ ! -s "${strip}" ]; then
	printf 'Nothing to strip: the tree already matches its archive.\n'
	exit 0
fi

# xargs -0 rather than -d '\n', which is a GNU extension and fails when this is run
# locally on macOS.
tr '\n' '\0' < "${strip}" | xargs -0 git rm --cached --quiet --

printf 'Stripped %d development file(s) from the release branch commit:\n' "$( wc -l < "${strip}" | tr -d ' ' )"
sed 's/^/  /' "${strip}"
