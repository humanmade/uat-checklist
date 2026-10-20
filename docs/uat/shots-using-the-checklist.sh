#!/usr/bin/env bash
#
# Takes every screenshot docs/uat/using-the-checklist.json uses, on the built Buttons page. Run from the repository
# root with S, UAT_URL and UAT_WP set, as .claude/skills/creating-a-uat-page/SKILL.md describes, and BUTTONS_ID set
# to the Buttons checklist's post ID for the pop-out shots. Pass shot names to take only those.
#
# admin-bar-menu shows the How to use the checklist link, which appears only once this guide is published: take it
# after the first build, then build again.

set -euo pipefail

: "${S:?Set S to the creating-a-uat-page scripts folder}"
: "${BUTTONS_ID:?Set BUTTONS_ID to the Buttons checklist ID}"

PAGE=/hub/buttons/
ITEM='#wp-admin-bar-uat-checklist'
FIRST_OPEN='.uat-checklist__details[open]'

shot() {
	local name="$1"
	shift

	if [[ ${#ONLY[@]} -eq 0 || " ${ONLY[*]} " == *" ${name} "* ]]; then
		node "$S/screenshot.mjs" using-the-checklist "$name" "$@"
	fi
}

ONLY=("$@")

shot admin-bar panel --path "$PAGE" --keep-closed \
	--steps "[{\"outline\":\"${ITEM}\"}]" --clip "[{\"page\":\"${ITEM}\"}]" --pad 24

shot admin-bar-phone panel --path "$PAGE" --width 390 --height 400 --keep-closed \
	--steps "[{\"outline\":\"${ITEM}\"}]" --clip '[{"page":"#wpadminbar"}]' --pad 0

shot panel-top panel --path "$PAGE" --steps '[{"open":0}]' \
	--clip '[{"page":".uat-panel__bar"},{"page":".uat-panel .uat-checklist__intro"}]' --pad 8

shot picker panel --path "$PAGE" --steps '[{"open":0}]' --clip '[{"page":".uat-panel__bar"}]' --pad 8

shot tag-required panel --path "$PAGE" --steps '[{"open":0}]' \
	--clip '[{"page":".uat-panel .uat-checklist__step[data-required] .uat-checklist__summary"}]' --pad 8

# A clip is cut to the window, so a shot of the end of the panel takes a window tall enough to show it.
shot tag-optional panel --path "$PAGE" --height 2000 --steps '[{"open":0}]' \
	--clip '[{"page":".uat-panel .uat-checklist__step:not([data-required]) .uat-checklist__summary"}]' --pad 8

shot count-meter panel --path "$PAGE" --steps '[{"open":0}]' \
	--clip '[{"page":".uat-panel .uat-checklist__meta"},{"page":".uat-panel .uat-checklist__meter"}]' --pad 8

shot step-open panel --path "$PAGE" --height 2000 --steps '[{"open":1}]' \
	--clip "[{\"page\":\".uat-panel ${FIRST_OPEN}\"}]" --pad 8

shot choose-pass panel --path "$PAGE" \
	--steps "[{\"open\":1},{\"clickPage\":\".uat-panel ${FIRST_OPEN} .uat-checklist__choice--pass\",\"wait\":300}]" \
	--clip "[{\"page\":\".uat-panel ${FIRST_OPEN} .uat-checklist__result\"}]" --pad 8

shot results-closed panel --path "$PAGE" --height 2000 \
	--steps '[{"result":[1,"fail"]},{"result":[2,"pass"]},{"result":[3,"pass"]},{"open":0}]' \
	--clip '[{"page":".uat-panel .uat-checklist__section"}]' --pad 8

shot fail-comment panel --path "$PAGE" \
	--steps "[{\"open\":1},{\"clickPage\":\".uat-panel ${FIRST_OPEN} .uat-checklist__choice--fail\",\"wait\":300},{\"clickPage\":\".uat-panel ${FIRST_OPEN} .uat-checklist__comment\"},{\"type\":\"The button does not open the link.\",\"wait\":300}]" \
	--clip "[{\"page\":\".uat-panel ${FIRST_OPEN} .uat-checklist__result\"}]" --pad 8

# Hovering scrolls the row into view without opening it.
shot notes-closed panel --path "$PAGE" --height 2000 --steps '[{"open":0},{"hover":".uat-panel .uat-checklist__notes-summary","wait":300}]' \
	--clip '[{"page":".uat-panel .uat-checklist__notes"}]' --pad 8

shot notes panel --path "$PAGE" --height 2000 \
	--steps '[{"open":0},{"clickPage":".uat-panel .uat-checklist__notes-summary","wait":600},{"clickPage":".uat-panel .uat-checklist__notes-form textarea"},{"type":"The examples look right at both widths.","wait":300}]' \
	--clip '[{"page":".uat-panel .uat-checklist__notes"}]' --pad 8

shot window-link panel --path "$PAGE" --steps '[{"open":0},{"outline":".uat-panel .uat-checklist__window"}]' \
	--clip '[{"page":".uat-panel .uat-checklist__header"}]' --pad 8

shot popout panel --path "${PAGE}?uat-view=window&uat-checklist=${BUTTONS_ID}" --width 500 --height 900 \
	--steps '[{"open":0}]' --clip '[{"page":".uat-panel"}]' --pad 0

shot instructions-link panel --path "/?uat-view=window&uat-checklist=${BUTTONS_ID}" --width 500 --height 900 \
	--steps '[{"open":0},{"outline":".uat-panel .uat-checklist__page"}]' \
	--clip '[{"page":".uat-panel .uat-checklist__header"}]' --pad 8

shot admin-bar-menu panel --path "$PAGE" --keep-closed \
	--steps "[{\"hover\":\"${ITEM}\",\"wait\":800}]" \
	--clip "[{\"page\":\"${ITEM}\"},{\"page\":\"${ITEM} .ab-sub-wrapper\"}]" --pad 8
