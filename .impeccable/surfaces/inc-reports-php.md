---
version: 1
slug: "inc-reports-php"
primary_target: "inc/reports.php"
related_targets: ["src/report.js", "src/report.scss", "src/panel.scss"]
---

# Report an issue

Scope: a button at the top right of every screen the tester can see the UAT panel on, front end and wp-admin, and
the modal it opens. Any logged-in user can submit. It exists whenever the plugin is active and has no
environment gate of its own. Visitor mode: Operate. The button is not added on block-editor screens, where it would
cover the editor's own top-right controls (the panel's footer entry still opens the dialog there), and neither the
button nor the footer entry appears in the panel's pop-out window.

Audience and job: a tester who hits a problem that is not a step in a checklist describes it, attaches a
screenshot and sends it without leaving the page. The site records who they are and which page they were on, so
the tester types nothing but the description. Content is fixed by the brief: a description field, a screenshot
upload button with the chosen image previewed above it, and a Submit button. Reports are read in wp-admin as a
custom post type with the statuses Pending, Investigating and Done, and through abilities, which can also set
the status.

## Direction contract

THESIS: One small control and one sheet. The button is a plain token-styled control at the top right, the modal
is the panel's sheet narrowed to a form. It refuses a feedback-widget look (floating coloured pill, emoji, badge).

OWN-WORLD: The panel's world, unchanged: theme background sheet, token borders and radius, heading font for the
title, muted text for hints. Pass and Fail are the only colours with meaning, and a report is neither, so nothing
here is red or green. Errors use text and the existing error treatment, never colour alone.

STORY: A tester on a page notices something wrong, clicks Report an issue at the top right, types what happened,
adds a screenshot and sees it above the upload button, submits, and reads a confirmation that stays until they
close the modal.

PLACEMENT: Fixed at the top right, below the admin bar, offset by `--uat-admin-bar-depth` as the panel is. No
inline styles. While the panel is closed the button sits at the inline-end edge. While the panel is open the
button leaves the page and the same action appears in the panel's footer, so it never overlaps the panel and
never needs a width calculation: the panel is `min( 30rem, 100vw )` and a button moved left of it would have no
room on a phone. The panel's open state is the single source of truth for which of the two is shown.

The footer entry is always visible and always on top: the footer is pinned to the bottom of the panel, so it does
not scroll away with a long checklist, and it stacks above the panel's scrolling content. The modal opens above
the panel (`z-index` above 99990, the panel's layer).

FIRST VIEWPORT: Modal over the page, labelled "Report an issue". Description field first, then the screenshot
preview (empty until one is chosen, with a Remove control once it is), then the upload button, then Submit. The
page and the signed-in user are shown as read-only lines so the tester sees what will be recorded. Opening moves
focus into the modal, Esc and the close control dismiss it, and focus returns to the control that opened it.

FORM: Operate control plus a native `<dialog>` opened with `showModal()`. Extension of the panel's controls and
step form (`src/_controls.scss`, `src/lib/step-form.js` preview), no concept roll.

STATES: Idle, uploading, submitted, and failed with the reason. The upload and submit statuses are announced to
assistive technology. Submit is disabled while a request is in flight. Motion respects `prefers-reduced-motion`.

OPEN DECISIONS: Whether a submitted report shows the tester a link to it. Today it cannot: the read-only page needs
the plugin's read capability, which a Subscriber does not hold, so the confirmation carries no link.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
