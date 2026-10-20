---
version: 1
slug: "inc-overview-php"
primary_target: "inc/overview.php"
related_targets: ["src/overview.scss"]
---

# UAT → Overview

Scope: the wp-admin page at UAT → Overview, `manage_options` only. Visitor mode: Operate.

Audience and job: the team running a testing round checks, between tester sessions, how much of the round is
tested, what failed, and which checklists need attention. Content is fixed by the brief: tests (steps in
published checklists), completed, not completed, passed, failed, site-wide and per checklist. A step's latest
result from any tester counts, as in the panel. Added on request: the percentage of required tests completed, and
each checklist's status (Fully tested once every required step has a result, In progress, Not started).

## Direction contract

THESIS: The panel's checklist header, widened into a page: title, an "x of y" progress line, a meter. It
refuses the KPI-tile grid; the five numbers are one relationship (tests = passed + failed + not completed).

OWN-WORLD: The panel's world, unchanged: theme background sheet on the admin canvas, token borders and radius,
heading font for titles, muted text for labels, Pass and Fail as the only colours, Fail hatched so it never
depends on hue. No icons, no cards inside the sheet.

STORY: An admin opens UAT, reads the headline count, sees the pass/fail/untested split at a glance, scans the
table for failures, and follows a checklist to its filtered Results.

FIRST VIEWPORT: Sheet at the top of the screen. H1 "Testing overview" and Results links on one row. Lead line
"60% of required tests completed" with the figure at heading size, then a muted tally of required and all tests.
Full-width segmented meter, Passed | Failed | track. A key row below: Passed, Failed, Not completed, each a
swatch, label and figure. Ruled off below it, the count of checklists Fully tested, In progress and Not started.
The checklist table, each name carrying a neutral status tag, starts within the first viewport at 1280×800.

FORM: Operate data page; extension of the panel's header anatomy, no concept roll (precisely specified surface
in an established world, shaped directly).

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
