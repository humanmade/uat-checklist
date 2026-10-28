=== UAT Checklist ===
Contributors: stuartshields
Tags: testing, uat, checklist, blocks, qa
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 0.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Testing checklists for user acceptance testing: testers mark each step Pass or Fail, and the results are stored on the site.

== Description ==

Write a checklist under **UAT → Checklists**, fill it with **Checklist section** and **Checklist step** blocks, and every logged-in member of the site can work through it from the **UAT** item in the admin bar, on the front end and in wp-admin alike. Each step is marked Pass or Fail, a Fail needs a comment, and administrators read every result under **UAT → Results**.

**UAT → Overview** gives administrators the round at a glance: how many tests the published checklists hold, how many are completed, and how many passed, failed or are still to do, across the site and per checklist.

A checklist is private: it has no front-end page, and only members of the site can read it. It can name an optional instructions page, which the panel links to and opens the checklist on.

The panel and the checklist take their colours, fonts, corner radius and spacing from the active theme's theme.json, so they look like the site they are activated on. Each value has a fallback for a theme that does not set it, and both are filterable: `hm_uat_checklist_style_fallbacks` and `hm_uat_checklist_style_tokens`.

A hub page listing UAT pages, and a guide to the panel, are optional. When a page with the slug `hub` is published at the top level, the admin bar links to it; `hm_uat_checklist_hub_slug` and `hm_uat_checklist_guide_slug` change the slugs or turn the links off.

Every interface string is translatable. A Japanese translation ships in `languages/`.

== Requires ==

WordPress 7.1 or later, and PHP 8.4.

== Changelog ==

= 0.5.0 =
* Any logged-in member of the site can report an issue. A **Report an issue** item in the admin bar, on the front end and in wp-admin, opens a dialog with a description, a screenshot upload previewed above its button, and **Submit report**. The report records who sent it and the page they were on. The UAT panel's footer, which is always visible, opens the same dialog.
* Reports are stored as the **UAT issue reports** post type, listed at **UAT → Issue reports**, and start as Pending. A UAT manager moves each to Investigating and then Done from the list's row actions and bulk action or from the edit screen, all through the REST API. A report is a record: its description, screenshot, author and date cannot be edited. Users who hold the read capability see every report's details, read-only, and cannot change anything.
* A tester can send 20 reports a day, and the screenshot limit is shared with results. The description is limited to 5,000 characters and the page address must be on the site; its query string and fragment are not stored.
* Four abilities for the WordPress MCP adapter: `list-reports`, `get-report`, `get-report-screenshot` and `set-report-status`, so an AI assistant can read reports and set their status.
* Reports are not offered in the block editor or the pop-out window. A Japanese translation ships with the strings.
* Screenshots of every type (JPEG, PNG, GIF and WebP) have embedded metadata, such as location, removed on upload, and an image over 10,000 pixels on a side or 50 million pixels in all is refused. The `hm_uat_checklist_screenshot_max_dimension` and `hm_uat_checklist_screenshot_max_pixels` filters change those limits. A screenshot counts only when it was uploaded by the author of the result or report it is on.
* A user can no longer make themselves a UAT manager by changing their own email address to a staff domain, through the REST API or a front-end form. An administrator, or the link mailed to the new address, still sets one.
* A report's description and page title in the abilities are marked as untrusted text a tester wrote, and `set-report-status` says to set a status only when the person using the assistant asks.

= 0.4.0 =
* The panel's step form keeps the latest Pass or Fail after a save and after a reload: its choice, its comment and its screenshot. A tester can add to the comment and save again, which records a new result and leaves the earlier one in the history. Once the step is marked fixed, the form starts blank for the retest.
* Saving again without choosing a new image keeps the screenshot, as a copy on the new result that does not count toward the day's limit. **Remove screenshot** saves the next result without one. If someone else saved the step in the meantime, the earlier screenshot is not kept, and the panel says so.

= 0.3.0 =
* A tester can send a screenshot with a result, from **Add a screenshot** under a step's comment in the panel. The chosen image is previewed full width above the button, which then offers to replace it, with **Remove screenshot** to clear it. JPEG, PNG, GIF and WebP images are accepted, up to the site's upload limit; anything else is refused and nothing is saved.
* The screenshot has its own column in **UAT → Results**, right of the comment. It shows large on the **Mark fixed** page, under the tester's comment, and in the panel's retest notice once the step is marked fixed. It is listed in the Media Library, and deleted with its result.
* A screenshot can be up to 20 MB, or the site's upload limit when lower, and a tester can add 50 a day. The `hm_uat_checklist_screenshot_max_bytes` and `hm_uat_checklist_screenshot_daily_limit` filters change them.
* A JPEG screenshot is saved without its metadata, so a phone photo does not publish where it was taken. This needs PHP's GD and exif extensions; without either, a JPEG is refused. A plugin converting JPEGs to WebP is set aside for that save.
* **Save result** spans the step form as the primary button, and **Add a screenshot** above it is a secondary one.
* **Mark fixed** has a **Don't send email notification** checkbox under the note. Ticked, the note is saved and shown in the panel, and the tester is not emailed. The `mark-fixed` ability takes the same choice as `notify` false.
* The **Mark fixed** page is drawn in the theme, as **UAT → Overview** is: the step and who failed it lead, then their comment and screenshot, then the note and its email choice, with **Mark fixed** and **Cancel**.

= 0.2.0 =
* A step can show a screenshot uploaded in the editor. Select the step, then upload an image or choose one from the Media Library under its text; the panel shows it there, above the step's instructions. Its alternative text is under **Screenshot** in the sidebar, and **Replace** and **Remove image** are in the block toolbar.

= 0.1.3 =
* `AGENTS.md` says to regenerate the translation template after a version bump, since the template's version comes from the plugin header.

= 0.1.2 =
* The checklist status constants in the progress code are declared before the functions that use them. No change in behaviour.

= 0.1.1 =
* The plugin now ships with its agent instructions (`AGENTS.md`, `CLAUDE.md`) and its `.claude/` folder, which holds the skill that builds UAT pages from specs.

= 0.1.0 =
* First release as a standalone plugin, generalised from a client project's UAT checklist.
* Checklists are a private `uat_checklist` post type, listed in the panel on every screen, each with an optional instructions page.
* Every save is a new result, so a tester's Fail is never overwritten. **Mark fixed** in Results adds a Fixed result, and the panel asks testers to retest the step.
* **Mark fixed** with a note emails the tester whose Fail it was: the step, the checklist, their comment, the note and a link to retest. It is sent by WP-Cron shortly after the save, through `wp_mail()`, in the tester's profile language when the site has it installed. No email goes for an empty note or a manager's own Fail. Deactivating the plugin removes any email still waiting to go.
* **Ready to retest** lists a tester's Fails that have since been marked fixed, in a dashboard widget, a notice on the Profile screen and a section at the top of the panel. A step leaves the list once the tester saves a new Pass or Fail on it.
* **UAT → Overview**: tests, completed, not completed, passed and failed, site-wide and per checklist, each step counted by its latest result as the panel shows it. It leads with the percentage of required tests completed, and marks each checklist Fully tested, In progress or Not started.
* **UAT → Results** lists every result, with **Latest result on each step only** to narrow it, which the overview's failed counts link to.
* Access by email domain: a UAT manager (an `@humanmade.com` address, or a user given Full access on their profile) writes checklists and marks fixes; every role above Subscriber reads the Overview and Results. `hm_uat_checklist_staff_domains` changes the domain.
* Abilities API support, for an AI assistant connected through the WordPress MCP adapter: read progress, read a checklist, list open failures, mark one fixed, and write checklists. Human Made staff only, by email domain. No ability records a Pass or a Fail.
* Styles read from the active theme's theme.json through `--hm-uat-*` design tokens, with filterable fallbacks.
* Hub and guide pages are optional, found by filterable slugs.
* Checklist content is single-language, written in the site's own language; the plugin's interface is translatable.
* `wp uat-checklist import-media` restores missing attachment files from a zip, on any host.
