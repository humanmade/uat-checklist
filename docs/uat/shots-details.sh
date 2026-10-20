#!/usr/bin/env bash
#
# Takes every screenshot docs/uat/details.json uses. Run from the repository root with S, UAT_URL and UAT_WP set,
# as .claude/skills/creating-a-uat-page/SKILL.md describes. Pass shot names to take only those.

set -euo pipefail

: "${S:?Set S to the creating-a-uat-page scripts folder}"

TOOLBAR='{"page":".block-editor-block-contextual-toolbar"}'
INSPECTOR='{"page":".block-editor-block-inspector"}'

shot() {
	local name="$1"
	shift

	if [[ ${#ONLY[@]} -eq 0 || " ${ONLY[*]} " == *" ${name} "* ]]; then
		node "$S/screenshot.mjs" details "$name" "$@"
	fi
}

ONLY=("$@")

shot add-block crop \
	--steps '[{"clickPage":".editor-document-tools__inserter-toggle","wait":1500},{"clickPage":".block-editor-inserter__search input"},{"type":"details","wait":2000},{"outline":".editor-block-list-item-details"}]' \
	--clip '[{"page":".editor-document-tools__inserter-toggle"},{"page":".block-editor-inserter__search"},{"page":".editor-block-list-item-details"}]' --pad 12

shot slash crop --block core/paragraph --steps '[{"type":"/details","wait":1500}]' \
	--clip '[{"canvas":".wp-block-paragraph"},{"page":".components-autocomplete__popover"}]' --pad 10

shot details-empty crop --block core/details --steps '[]' \
	--clip "[${TOOLBAR},{\"canvas\":\".wp-block-details\"}]" --pad 12

# Inserted filled, since typing into the content area from a script lands in the summary.
shot details-filled admin --start new:page \
	--steps '[{"insert":"<!-- wp:details {\"showContent\":true} --><details class=\"wp-block-details\" open><summary>Who can test this site?</summary><!-- wp:paragraph --><p>Anyone with an account on it.</p><!-- /wp:paragraph --></details><!-- /wp:details -->","wait":1500}]' \
	--clip '[{"canvas":".wp-block-details"}]' --pad 12

shot details-settings crop --block core/details --steps '[]' --clip "[${INSPECTOR}]" --pad 4
