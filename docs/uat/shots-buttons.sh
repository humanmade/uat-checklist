#!/usr/bin/env bash
#
# Takes every screenshot docs/uat/buttons.json uses. Run from the repository root with S, UAT_URL and UAT_WP set,
# as .claude/skills/creating-a-uat-page/SKILL.md describes. Pass shot names to take only those.

set -euo pipefail

: "${S:?Set S to the creating-a-uat-page scripts folder}"

TOOLBAR='{"page":".block-editor-block-contextual-toolbar"}'
INSPECTOR='{"page":".block-editor-block-inspector"}'
CLOSE_DRAWER='{"preference":["core/block-editor","linkControlSettingsDrawer",false]}'
LABEL='{"type":"Read the guide","wait":600}'

shot() {
	local name="$1"
	shift

	if [[ $# -gt 0 && ( ${#ONLY[@]} -eq 0 || " ${ONLY[*]} " == *" ${name} "* ) ]]; then
		node "$S/screenshot.mjs" buttons "$name" "$@"
	fi
}

ONLY=("$@")

shot add-block crop \
	--steps '[{"clickPage":".editor-document-tools__inserter-toggle","wait":1500},{"clickPage":".block-editor-inserter__search input"},{"type":"buttons","wait":2000},{"outline":".editor-block-list-item-buttons"}]' \
	--clip '[{"page":".editor-document-tools__inserter-toggle"},{"page":".block-editor-inserter__search"},{"page":".editor-block-list-item-buttons"}]' --pad 12

shot buttons-empty crop --block core/buttons --select core/button --steps '[]' \
	--clip "[${TOOLBAR},{\"canvas\":\".wp-block-buttons\"}]" --pad 12

shot button-link crop --block core/buttons --select core/button \
	--steps "[${CLOSE_DRAWER},${LABEL},{\"button\":\"Link\",\"within\":\".block-editor-block-contextual-toolbar\",\"wait\":1200},{\"type\":\"https://wordpress.org/\"},{\"press\":\"Enter\",\"wait\":1200}]" \
	--clip "[${TOOLBAR},{\"page\":\".components-popover .block-editor-link-control\"}]" --pad 10

shot button-link-tab crop --block core/buttons --select core/button \
	--steps "[${CLOSE_DRAWER},${LABEL},{\"button\":\"Link\",\"within\":\".block-editor-block-contextual-toolbar\",\"wait\":1200},{\"type\":\"https://wordpress.org/\"},{\"press\":\"Enter\",\"wait\":1200},{\"button\":\"Edit link\",\"wait\":1000},{\"button\":\"Advanced\",\"within\":\".block-editor-link-control\",\"wait\":1000},{\"clickPage\":\".block-editor-link-control input[type=checkbox]\",\"wait\":600}]" \
	--clip "[${TOOLBAR},{\"page\":\".components-popover .block-editor-link-control\"}]" --pad 10

shot slash-buttons crop --block core/paragraph --steps '[{"type":"/buttons","wait":1500}]' \
	--clip '[{"canvas":".wp-block-paragraph"},{"page":".components-autocomplete__popover"}]' --pad 10

shot row-add-before crop --block core/buttons --steps '[]' --clip "[{\"canvas\":\".wp-block-buttons\"}]" --pad 6

shot row-add-after crop --block core/buttons \
	--steps '[{"click":".wp-block-buttons .block-editor-button-block-appender","wait":1200}]' \
	--clip "[{\"canvas\":\".wp-block-buttons\"}]" --pad 6

shot button-styles crop --block core/buttons --select core/button --steps '[]' \
	--clip '[{"page":".block-editor-block-inspector .block-editor-block-card"},{"page":".block-editor-block-inspector .block-editor-block-styles"}]' --pad 4

shot buttons-justify crop --block core/buttons \
	--steps '[{"button":"Change items justification","within":".block-editor-block-contextual-toolbar","wait":1000}]' \
	--clip "[${TOOLBAR},{\"page\":\".components-popover .components-dropdown-menu__menu\"}]" --pad 10

shot pattern-insert crop \
	--steps '[{"clickPage":".editor-document-tools__inserter-toggle","wait":1500},{"clickPage":"[role=tab]:has-text(\"Patterns\")","wait":1500},{"clickPage":".block-editor-inserter__search input"},{"type":"centered heading","wait":2500}]' \
	--clip '[{"page":".block-editor-tabbed-sidebar__tablist-and-close-button"},{"page":".block-editor-inserter__search"},{"page":".block-editor-block-patterns-list__list-item"}]' --pad 12

shot pattern-locked crop --pattern twentytwentyfive/cta-centered-heading --as-pattern --select core/group \
	--steps '[]' --clip "[${INSPECTOR}]" --pad 4
