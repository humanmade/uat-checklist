# UAT Checklist

A user acceptance testing panel for any WordPress site. Write a checklist under **UAT → Checklists**, and every
logged-in member of the site works through it from **UAT** in the admin bar, on any screen, marking each step
Pass or Fail with a comment and, optionally, a screenshot. Results are saved on the site, and administrators
read them under **UAT → Results**. A page of instructions for a checklist is optional.

The panel and the checklist take their colours, fonts, corners and spacing from the active theme's theme.json,
so on any site they look like that site. Every value the theme does not set has a fallback, and both are
filterable.

## Requirements

- WordPress 7.1 or later, and PHP 8.4.
- A block theme, or a classic theme with a theme.json, for the styling. Without one, the panel takes core's
  default styles, such as its dark grey button, and the fallbacks.
- JavaScript in the tester's browser: saving goes through the REST API.

## Installing

Install the `release` branch or a release zip from GitHub, not `main`: only those carry the compiled `build/`
directory, and without it the plugin activates and registers nothing. Activate it on the site being tested,
never network-wide, since every member of a site it is active on can record results.

To work on the plugin itself:

```sh
composer install && nvm use && npm ci
npm run build
npm run env:start        # WordPress at http://localhost:3034, with the plugin active
```

## Writing a checklist

1. Go to **UAT → Add checklist** in wp-admin. Only a UAT manager can, as **Who can do what** below describes.
2. Give it a title. Testers pick it by that title in the panel.
3. Write the intro in the paragraph at the top, such as the tickets the checklist tests. The panel shows it
   under the title; left empty, it shows nothing. Paragraphs above the first section are all part of the intro.
4. Add a **Checklist section** for each part of the test, such as "In the editor" or "At mobile width". The
   **Testing checklist** block they go in is already there, and each checklist holds one.
5. Add a **Checklist step** to a section for each check: what the tester does, and what they should see.
6. Publish it. It appears in the panel's picker, on every screen, for every logged-in member of the site.

A checklist has no front-end page of its own, and nothing about it is public. A draft does not appear in the
panel.

A step is required unless its `required` attribute is false. The panel tags required steps and leads its count
with them, so a tester can finish those and stop. Each step gets a `stepId` UUID when it is added, and its
results are stored against it: rewording a step keeps them, and deleting the step leaves them in **Results**.

A step can show one screenshot, for a control that is hard to find. Select the step, then upload an image or
choose one from the Media Library in the space under its text. The panel shows it in the same place, above the
step's instructions. Write what it shows under **Screenshot** in the sidebar, for testers who cannot see it.
**Replace** and **Remove image** are in the block toolbar.

Writing steps by hand suits a short checklist. To generate checklists from JSON specs, optionally with a page
of instructions, screenshots and live examples for each, use the `creating-a-uat-page` skill in
`.claude/skills/`. It reaches WordPress through WP-CLI on any host and writes in the site's own language.

## Linking a checklist to its instructions

A checklist can name a page as its **Instructions page**, in the editor sidebar. Then:

- the panel shows **Open the instructions**, which opens that page in a window of its own;
- a tester visiting that page finds the panel opening on that checklist;
- the pop-out window, from **Open in new window**, opens on that page.

With no instructions page, there is no link, the panel opens on the tester's last checklist, and the pop-out
opens on the home page. A page that is not published is treated as none.

## The overview

**UAT → Overview** is the first item under **UAT**, so the menu opens on it. It counts every step in a published
checklist as one test:

- **Completed** steps have a Pass or Fail as their latest result, from any tester; **not completed** steps have
  none, or have been marked fixed and wait for a retest.
- **Passed** and **failed** go by each step's latest result, whoever saved it, which is what the panel shows.

The page leads with the share of required steps that have a result, rounded down so it reads 100% only when
every one has. A checklist with no step marked required counts all its steps, as the panel does. Each checklist
is **Fully tested** once every required step has a result, **In progress** once any step has one, and **Not
started** before that; the summary counts how many are at each.

The figures for the whole site come first, then one row per checklist, by title, with its status, its share, how
many steps wait for a retest, and links to its results, its editor and its instructions page. A draft checklist is
left out until it is published, and a result on a step since deleted from its checklist counts nowhere, though it
stays in **Results**. Each segment of a meter names its count on hover.

A failed count links to **Results** with **Latest result on each step only** ticked, so the list holds the same
failures the overview counted, and not a Fail another tester has since passed.

## Reading results

**UAT → Results** in wp-admin lists every result, newest first. **Pass**, **Fail**, **Fixed** and **Additional
comments** above the list filter it, and the checklist dropdown limits it to one. **Latest result on each step
only** leaves the one result per step the panel and the overview go by. The counts beside the statuses are core's,
and still count every result. **Move to Trash** clears results, for example before a retest round. Results cannot
be edited. A tester's screenshot shows under their comment, linked to the full image.

Deleting a checklist permanently, from the trash, deletes its results, their screenshots and testers' additional
comments with it. A checklist in the trash keeps them, so restoring it brings its history back.

Every save is a new result, so nothing a tester saved is overwritten: a step's results read as its history, and
the latest from anyone is what every tester sees.

## Marking a failure fixed

Once a failed step is fixed, a UAT manager clicks **Mark fixed** under that Fail in **Results**, and may add a note
for the testers. It shows only while the step's latest result is a Fail, and it covers every tester's Fail on the
step. Marking it saves a **Fixed** result of its own and leaves the Fail as it was. The page it opens shows the
tester's comment, with their screenshot when they sent one.

While a Fixed is the step's latest result, the panel tags the step **Retest** and opens it first, with the note,
the failure it fixed and that failure's screenshot. The tester's next Pass or Fail decides the step, and the overview counts the step as not
completed until then.

When the manager writes a note, the tester whose Fail it was also gets an email. It names the step and the
checklist, quotes the tester's comment and the note, and links to retest. WP-Cron sends it shortly after the save,
so on a site with WP-Cron disabled it goes when the system cron next runs. It goes through `wp_mail()`, so the
site's own mailer applies. It is written in the tester's profile language, provided the site has that language
installed. No email goes when the note is empty, or when the manager marked their own Fail. The
`uat-checklist/mark-fixed` ability sends it too. Deactivating the plugin removes any email still waiting to go,
since nothing would be there to send it.

**Ready to retest** lists each tester's own Fails that have since been marked fixed, with or without a note. It
shows in a dashboard widget, in a notice on the Profile screen (WordPress sends Subscribers there after login, not
to the dashboard), and in a section at the top of the panel, where a click opens that step. A step leaves the list
once the tester saves a new Pass or Fail on it. With nothing to retest, nothing is shown.

## Who can do what

A **UAT manager** is a user whose email is at `humanmade.com`, or one given **Full access to UAT checklists** on
their profile. Only a user at that domain sees the checkbox, so access given by it cannot be passed on. The
`hm_uat_checklist_staff_domains` filter changes the domain.

|                                | UAT manager | Contributor and above | Subscriber |
| ------------------------------ | ----------- | --------------------- | ---------- |
| Save Pass or Fail in the panel | Yes         | Yes                   | Yes        |
| Write checklists               | Yes         | No                    | No         |
| Read the Overview and Results  | Yes         | Yes                   | No         |
| Mark fixed, Move to Trash      | Yes         | No                    | No         |

The plugin grants its own two capabilities, `manage_uat_checklists` and `read_uat_results`, so roles do not need
changing.

## Matching the site's design

The stylesheets read only `--hm-uat-*` custom properties. `inc/styles.php` sets them from the theme's global
styles and settings:

| Token                              | From theme.json                                                     | Fallback  |
| ---------------------------------- | ------------------------------------------------------------------- | --------- |
| `color-accent`                     | `styles.elements.button.color.background`, then the link colour     | `#2271b1` |
| `color-on-accent`                  | `styles.elements.button.color.text`                                 | `#ffffff` |
| `color-text`, `color-background`   | `styles.color.text`, `styles.color.background`                      | `#1e1e1e`, `#ffffff` |
| `color-pass`, `color-fail`         | A palette colour with the slug `success` or `green`, and `error`, `danger` or `red` | `#008a20`, `#d63638` |
| `font-family`, `font-family-heading` | `styles.typography.fontFamily`, `styles.elements.heading.typography.fontFamily` | `inherit` |
| `font-size`                        | `styles.typography.fontSize`, held between 0.875rem and 1rem        | `1rem`    |
| `radius-control`                   | `styles.elements.button.border.radius`                              | `4px`     |
| `space-xs` to `space-4-xl`         | The theme's spacing sizes, by position, each capped for a 30rem panel | `0.25rem` to `3rem` |
| `measure`                          | `settings.layout.contentSize`, at most `40em`                       | `40em`    |

Hover, tint, border and muted-text colours are mixed from those with `color-mix()`, so a theme that sets two
colours still gets a full set. The tokens are declared on the plugin's own elements with `:where()`, at zero
specificity, and in wp-admin the theme's preset variables are copied onto the panel, so the panel matches the
front end there too.

Change a fallback, or any final value, from a theme or a plugin:

```php
add_filter( 'hm_uat_checklist_style_fallbacks', function ( array $fallbacks ): array {
	$fallbacks['color-pass'] = '#1a7f37';
	return $fallbacks;
} );

add_filter( 'hm_uat_checklist_style_tokens', function ( array $tokens ): array {
	$tokens['color-accent'] = 'var(--wp--preset--color--primary)';
	return $tokens;
} );
```

A plain CSS rule works too, since any class selector outranks `:where()`:

```css
.uat-panel { --hm-uat-color-accent: rebeccapurple; }
```

## Filters

| Filter                                     | Receives                         | Default                                         |
| ------------------------------------------ | -------------------------------- | ----------------------------------------------- |
| `hm_uat_checklist_style_fallbacks`         | Token values, keyed by name      | The fallbacks above                             |
| `hm_uat_checklist_style_tokens`            | Final token values, global styles | The theme's values over the fallbacks          |
| `hm_uat_checklist_hub_slug`                | Slug of the hub page             | `hub`; empty turns the link off                 |
| `hm_uat_checklist_guide_slug`              | Slug of the guide to the panel   | `using-the-checklist`; empty turns the link off |
| `hm_uat_checklist_window_hidden_selectors` | Selectors hidden in the pop-out  | The block theme's header and footer             |
| `hm_uat_checklist_staff_domains`           | Email domains of UAT managers    | `[ 'humanmade.com' ]`                           |

## The hub page and the guide

Both are optional, as are pages of any kind: the panel needs only checklists. A published top-level page with
the hub slug gets a **UAT hub page** link under **UAT** in the admin bar. A published page with the guide slug,
under the hub or at the top level, gets **How to use the checklist**. With neither, the admin bar item opens the
panel and has no menu.

## REST routes

The panel's own routes are under `/wp-json/uat-checklist/v1/`, for a logged-in user who can `read` on the
site.

| Route                           | Does                                                                          |
| ------------------------------- | ----------------------------------------------------------------------------- |
| `GET checklists`                | Every published checklist, with progress, for the picker                      |
| `GET checklists/<checklist ID>` | One checklist, rendered for the current tester                                |
| `GET retests`                   | The current user's Fails marked fixed and not yet retested (Ready to retest)  |
| `POST results`                  | Saves `checklist_id`, `step_id`, `status` (`pass` or `fail`), `comment` and an optional `screenshot` file |
| `POST notes`                    | Saves `checklist_id` and `comment` as additional comments; empty deletes them |

A comment is required with `fail` and capped at 2,000 characters. The step text stored is read from the
checklist, not from the request. One user can save 30 results a minute; past that, `POST results` answers 429
until the minute has passed.

`screenshot` makes `POST results` a multipart request. Any member who can save a result can send one, including
a Subscriber, who cannot use the Media Library. It must be a JPEG, PNG, GIF or WebP image, checked by its content,
no larger than the site's upload limit. A file that fails either check is refused with a 400 and nothing is saved.

Checklists are also at core's `/wp-json/wp/v2/uat-checklists`, which the block editor reads and saves through.
Core would serve a published checklist there to anyone; the plugin refuses every request to it from a user who
is not a logged-in member of the site. The instructions page is the `_uat_instructions_page` meta field, which
only a user who can edit the checklist can set.

## Connecting an AI assistant

The plugin registers five abilities with WordPress's Abilities API. An AI assistant reaches them through the
[WordPress MCP adapter](https://github.com/WordPress/mcp-adapter), a separate plugin, which lists them once both
plugins are active. The assistant acts as the WordPress user it connects as, and only a user whose email is at
`humanmade.com` can use any of them. The `hm_uat_checklist_staff_domains` filter sets the domain, as it does for UAT
managers. **Full access to UAT checklists** on a profile does not open the abilities.

| Ability                           | Does                                                                |
| --------------------------------- | ------------------------------------------------------------------- |
| `uat-checklist/get-progress`   | The overview's counts, site-wide and for each published checklist   |
| `uat-checklist/get-checklist`  | One checklist's sections and steps, with each step's latest result  |
| `uat-checklist/list-failures`  | Every step whose latest result is a Fail, with the tester's comment |
| `uat-checklist/mark-fixed`     | **Mark fixed** on one of those Fails, with an optional note         |
| `uat-checklist/save-checklist` | Creates a checklist, or replaces one's sections and steps           |

No ability saves a Pass or a Fail: testers do that in the panel. `save-checklist` keeps a step's results when the
step keeps the `id` that `get-checklist` returned, and refuses an `id` the checklist does not have. A new
checklist is a draft unless `status` is `publish`. The abilities are not offered on core's
`/wp-json/wp-abilities/v1` routes.

With WP-CLI on the site, an MCP client can run `wp mcp-adapter serve --user=<login>` and talk to it over STDIO.
Otherwise it connects over HTTP to `/wp-json/mcp/mcp-adapter-default-server` with an application password; the
adapter's [CLI usage guide](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/cli-usage.md) has
the client configuration for both. The local `npm run env:start` site has the adapter installed.

## How a result is stored

One `uat_result` post per save, never updated, in post columns only:

| Column         | Holds                                                                      |
| -------------- | -------------------------------------------------------------------------- |
| `post_author`  | The tester, or for a Fixed result the UAT manager who marked it            |
| `post_parent`  | The checklist                                                              |
| `post_status`  | `uat-pass`, `uat-fail`, or `uat-fixed`                                     |
| `post_title`   | The step text as it read when saved                                        |
| `post_content` | The comment, or the note on a Fixed result                                 |
| `post_name`    | `<user ID>-<step ID>`, with `-2`, `-3` added by WordPress to later saves   |

Additional comments use the status `uat-note`, one post per tester per checklist, under the slug
`note-<checklist ID>-<user ID>`.

A screenshot sent with a result is an image attachment whose `post_parent` is the result and whose author is the
tester, so it is listed in the Media Library. Deleting the result for good, alone or with its checklist, deletes the
screenshot and its file.

## Restoring media after a database move

`wp uat-checklist import-media` downloads a zip and writes each attachment's missing file at the path the
attachment already names. It never creates, changes or deletes an attachment, a file already present is left
alone, and a second run writes nothing. The zip holds files as they sit under `wp-content/uploads`, so a
network site's are under `sites/<site ID>/`.

```sh
wp --url=example.com uat-checklist import-media --zip=https://example.com/uat-media.zip --dry-run
```

Drop `--dry-run` to write. A Google Drive file shared with anyone who has the link works as
`https://drive.usercontent.google.com/download?id=<file ID>&export=download&confirm=t`, in quotes.

## Uninstalling

Deactivating the plugin unschedules any retest email still waiting to go. Deleting it removes nothing from the
database, because the results are the record of the testing round. Checklists, results, additional comments, the
`_uat_instructions_page` meta and each user's `hm_uat_checklist_full_access` grant all stay.

To remove the data, delete each checklist permanently while the plugin is still active: its results and
additional comments are deleted with it. Then delete the plugin.

## Translations

Every string is translatable under the `uat-checklist` text domain, and a Japanese translation ships in
`languages/`. Checklist content is written in the site's own language and is not translated by the plugin.
`bin/i18n` regenerates the POT and compiles each catalog after `npm run build`.

## Limits

- Results are not exported. The list screen is the report.
- Any member of the site who can `read` can record results, subscribers included.
- The picker and the overview list the first 200 published checklists by title.
- One checklist reads up to 100 additional comments; past that, the oldest are not read.
- One user can save 30 results a minute.
