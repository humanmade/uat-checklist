# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Testers:** any logged-in member of the site being tested, from client stakeholders to developers. They work
  through a checklist from the **UAT** panel in the admin bar, on any screen, marking each step Pass or Fail.
- **Administrators** (`manage_options`): the agency or project team running the round. They read what has been
  tested, what failed and what is left, under **UAT → Overview** and **UAT → Results**.
- **Editors and Administrators** write checklists under **UAT → Checklists**.

## Product Purpose

A WordPress plugin that runs user acceptance testing inside the site being tested. Checklists of sections and
steps are written in the block editor, testers record a result against each step, and the results stay on the
site. Success is a team knowing, at any moment, which steps pass, which fail and which nobody has tried.

## Positioning

The testing happens on the site itself, in the site's own look: the panel and every UAT surface read the active
theme's theme.json, so a tester never leaves the thing they are testing.

## Operating Context

- A testing round runs over days or weeks. Results are trashed before a retest round.
- Several testers can mark the same step. The latest result from anyone is what the panel shows, and what counts.
- Checklists are often generated from JSON specs by the `creating-a-uat-page` skill, with an optional
  instructions page each.

## Capabilities and Constraints

- A **test** is one step in a published checklist. A step is **completed** once it has a result.
- Results are not exported; the Results list is the detailed report.
- WordPress 7.1+, PHP 8.4. Checklist content is single-language, in the site's own language; the interface is
  translatable, and a Japanese translation ships.
- Limits: 200 checklists in the picker; one checklist reads up to 200 steps from up to 20 testers.

## Brand Commitments

- Every visual value comes from the active theme's theme.json through the `--hm-uat-*` tokens, with filterable
  fallbacks. No stylesheet holds its own colour, size or font.
- Pass and Fail are the only colours with meaning. Colour in the plugin's UI always means a result.

## Evidence on Hand

No real client data ships with the plugin. Screenshots and examples come from the site under test.

## Product Principles

- The tester's place in the site comes first; the plugin takes the site's look rather than imposing its own.
- Every count agrees with what testers see in the panel.
- Results are a record: never edited, only read or cleared.

## Accessibility & Inclusion

Pass and Fail must never rely on colour alone. Every result carries its label, and the fallback red and green are
indistinguishable under deuteranopia.
