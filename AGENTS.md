# UAT Checklist

A standalone WordPress plugin for user acceptance testing. Checklists are a private post type, each holding one
**Testing checklist** block of sections and steps. Logged-in testers mark each step Pass or Fail from a panel
opened by **UAT** in the admin bar on any screen, and the results are stored on the site. It is styled entirely
from the active theme's theme.json.

It was generalised from a client project's UAT plugin, where each checklist lived in a page's content. Four
things changed in that move: checklists became their own post type with an optional instructions page, the
styling reads any theme rather than one, checklist content is single-language rather than English with Japanese
twins, and the hub page is optional. Moving that plugin's pages across is the client project's own command, not
this plugin's. `README.md` is the user documentation; this file is for working on the plugin.

## Architecture

| File                    | Responsibility                                                                     |
| ----------------------- | ---------------------------------------------------------------------------------- |
| `uat-checklist.php`  | Header, `VERSION`, `PATH`, `FILE`, requires, calls `bootstrap()`                   |
| `inc/namespace.php`     | The module registry; each new module is added to `bootstrap()`                     |
| `inc/i18n.php`          | Loads the text domain from `languages/`                                            |
| `inc/styles.php`        | The `--hm-uat-*` design tokens, read from theme.json, and their two filters        |
| `inc/blocks.php`        | Registers the blocks from `build/blocks/`, finds steps in content, the pop-out view |
| `inc/checklists.php`    | The `uat_checklist` post type, its instructions page, and its core REST lockdown   |
| `inc/results.php`       | The `uat_result` post type, its statuses, and reading and saving results           |
| `inc/screenshots.php`   | A tester's screenshot on a result: the upload, its attachment, its markup, its deletion |
| `inc/rest.php`          | The five REST routes the panel uses                                                |
| `inc/admin.php`         | The **Results** list: columns, filters, **Mark fixed**, no edit screen             |
| `inc/notifications.php` | The email to a tester when their Fail is marked fixed with a note, sent from a single cron event |
| `inc/retests.php`       | A tester's **Ready to retest** list: the dashboard widget and the Profile screen notice |
| `inc/progress.php`      | Each published checklist's counts, by each step's latest result; no hooks          |
| `inc/access.php`        | Who is a UAT manager, the plugin's two capabilities, and the profile checkbox      |
| `inc/overview.php`      | **UAT → Overview**: the counts from `progress.php`, site-wide and per checklist    |
| `inc/abilities.php`     | The Abilities API abilities an AI assistant uses through the WordPress MCP adapter |
| `inc/panel.php`         | The admin bar item, the optional hub and guide links, the panel's assets and the checklist it opens with |
| `inc/guide.php`         | The stylesheet for a UAT page's how-to content                                     |
| `inc/cli.php`           | `wp uat-checklist import-media`                                                    |
| `blocks/<slug>/`        | `checklist`, `section` and `step`: block.json, editor script, render.php           |
| `src/panel.js`, `src/lib/` | The panel, the step and comment forms, the pop-out window                       |
| `src/checklist-editor.js` | The Instructions page setting in a checklist's editor sidebar                    |
| `src/*.scss`            | Panel, guide, overview and shared control styles; `_tokens.scss` reads the tokens  |
| `PRODUCT.md`, `.impeccable/` | Product record and surface briefs for the impeccable design skill; not shipped |
| `webpack.config.js`     | Builds `src/*.js` to `build/`, `blocks/*/index.js` to `build/blocks/`, and the i18n map |
| `bin/i18n`              | POT, catalogs and their compiled forms                                             |
| `.claude/skills/creating-a-uat-page/` | The skill that builds UAT pages from specs; see "The skill" below    |

## Decisions that are load-bearing

Change these only with the reasoning in mind.

### Every style value is a token, and every token comes from the theme first

No stylesheet holds a colour, size or font. `src/_tokens.scss` exposes one function, `token( name )`, which
reads `var( --hm-uat-<name> )`. `inc/styles.php` declares those properties, taking each from the theme's
merged global styles (`wp_get_global_styles()`) or settings, and from `fallbacks()` when the theme is silent.
Derived shades (hover, tint, border, muted text) are `color-mix()` expressions over the base tokens, and they
are fallbacks too, so a filter can replace any of them.

`hm_uat_checklist_style_fallbacks` changes what is used when the theme says nothing;
`hm_uat_checklist_style_tokens` has the last word. Adding a token means adding it to `fallbacks()` first, then
to `tokens()` if theme.json has a value for it.

### The tokens are declared on the plugin's elements, not on `:root`

A custom property holding `var(--wp--preset--color--x)` resolves on the element that declares it. In wp-admin
the theme's preset variables exist only on the panel, where `Panel\scoped_theme_variables()` copies the
theme's `:root` rule, because wp-admin does not load the theme's styles. Tokens declared on `:root` would
resolve to nothing there. `Styles\SCOPE` lists the elements; a new top-level element the stylesheets style
needs adding to it.

The declaration is wrapped in `:where()`, at zero specificity, so a site can override a token with any class
selector as well as through the filters.

### Page-scale theme values are bounded

The panel is 30rem wide. A theme's body size and spacing scale are sized for page layout, and Twenty
Twenty-Five's run the panel's text to about 25px. `FONT_SIZE_MIN` and `FONT_SIZE_MAX` clamp the body size, and
`SPACING_STEPS` caps each spacing step, so the theme's values are used without breaking the layout.

### One stylesheet loads the tokens, and every other one depends on it

`Styles\HANDLE` is registered with no file, and its rules are added inline on the first enqueue hook of a
request. The panel and guide stylesheets name it as a dependency, and `Blocks\depend_on_tokens()` adds it to
each block's registered stylesheets. The editor canvas, the front end and wp-admin therefore all print it once,
before anything that reads it.

### Blocks register from `build/blocks/`, never from `blocks/`

`webpack.config.js` copies each `block.json` and its PHP beside the compiled script, and
`register_block_type_from_metadata()` is pointed there. An edited `blocks/step/render.php` changes nothing until
`npm run build`, with no error. `Blocks\version_styles()` versions each block's stylesheets with the build hash,
because core uses block.json's fixed `version` and a browser would keep the old CSS.

### A checklist is its own post, and pages are optional

The panel's subject is the checklist, not a page. Each `uat_checklist` post holds one Testing checklist block,
put there by the post type's locked template; the block's `supports.inserter` is false, so it cannot be added to
anything else. Results and notes have the checklist as `post_parent`. A page relates to a checklist only through
the checklist's `_uat_instructions_page` meta, which drives three things and nothing more: the "Open the
instructions" link, the panel opening on that checklist when a tester visits the page (`Panel\current_checklist()`,
passed to `src/panel.js` as `window.hmUatChecklist.current`), and where the pop-out window opens.

The pop-out names its checklist in the `uat-checklist` query variable, so it works with no page at all: it
opens on the home page in the window view.

### Core's REST routes for checklists are closed to anyone but members

The block editor needs `show_in_rest`, and core then serves any published post of the type at
`/wp/v2/uat-checklists` to any visitor, because it checks the post status and not the post type's `public` flag.
`Checklists\restrict_rest()` refuses every request to that route from a user `Results\can_record()` rejects. A
test removes the filter for one request and gets a 200, so the test fails if the filter goes.

### Checklist content is one language: the site's

Steps, sections and titles are written in whatever language the site is in. The plugin translates its own
interface only. The client version stored English and Japanese twins of every attribute and switched between
them per tester; that model was dropped as client-specific. The skill detects the site's locale and writes
specs in it.

### A result is a record that is never overwritten, and has no edit screen

Every **Save result** inserts a new `uat_result` post, so a client's Fail survives their retest. The latest result
on a step, from anyone, is what the panel shows and what the overview counts; everything older is history.
**Mark fixed** is a result of its own, status `uat-fixed`, saved by the developer: it leaves the Fail untouched,
and while it is a step's latest the panel asks for a retest. A tester can only save Pass or Fail
(`Results\STATUSES`); Fixed is `Results\FIXED_STATUS`, outside that set, so REST cannot accept it.

A tester's **Ready to retest** list is their Fails whose step's latest result is Fixed, worked out from results on
each request (`Progress\awaiting_retest()`). Nothing extra is stored, so a step leaves the list when the tester
next saves a Pass or Fail. It shows in three places, because login sends a Subscriber to the Profile screen and
never the dashboard: a dashboard widget, a notice on the Profile screen, and the top of the panel. Separately, a
Mark fixed with a note emails the tester whose Fail it was, from a single `wp_schedule_single_event()` so the
redirect never waits on mail and a mail failure cannot fail the save. No email goes when the note is empty or the
manager marked their own Fail. Deactivating the plugin unschedules every queued email
(`Notifications\unschedule_all()`): with the plugin inactive, cron would drop them unsent.

The slug starts `<user ID>-<step ID>`. WordPress adds `-2`, `-3` to repeats, so the step ID is the 36 characters
after the first hyphen (`Results\step_id_of()`); a step ID is always a 36-character UUID. Everything else is in
post columns: `inc/results.php` holds the contract, README.md's "How a result is stored" the summary.

`Results\result_ids_by_step()` reads every result's ID and slug on a checklist in one uncapped query, cached for
the request, and callers load only the posts they need: the latest on each step (`get_checklist_results()`), or one
step's history (`get_step_history()`). The panel's checklist list counts every published checklist on each open,
so loading whole histories there would grow with every save on the site.

`REST\save_result()` refuses a user's save past `RATE_LIMIT` in `RATE_WINDOW` with a 429, and stores nothing. It
counts from `post_date_gmt`, so no counter is kept. Only the panel's route is limited: Mark fixed, from the Results
list or the `mark-fixed` ability, saves through `Results` without it.

A tester's screenshot is not in the result's columns. It is an image attachment whose `post_parent` is the result,
so a result keeps the one it was saved with and a retest brings its own. `REST\save_result()` takes it through
`Screenshots\store()` before saving the result, so a refused file stores nothing, and the route accepts it from any
member who can record, Subscribers included, because core's media route would need `upload_files`.
`wp_handle_upload()` checks the type by content, but only for a file that arrived in an HTTP POST, which is why the
type check is tested in `tests/e2e/` and not in `tests/wp/`. `Screenshots\delete_with_result()` deletes it on
`before_delete_post`: core would otherwise move it to the checklist and orphan the file.

Stored comments, notes and step text are HTML-safe, not raw: `sanitize_textarea_field()` stores a lone `<` as
`&lt;`, and kses stores `&` as `&amp;` for a user without `unfiltered_html`. HTML output escapes them, and
`esc_html()` does not encode them twice. Anything shown as text (an email, JSON the panel sets as `textContent`,
an ability's output) goes through `Results\plain_text()`. `Blocks\checklist_title()` is plain text for the same
reason, so it is escaped wherever it reaches HTML, the pop-out's `<title>` included. The abilities return the stored
title decoded rather than texturized, because `get-checklist` feeds `save-checklist`, and a texturized title would
write curly quotes back over the author's.

The classic edit screen offers none of the statuses nor the comment, so saving from it would move a result to
`publish` and drop it from the list. `Admin\deny_editing()` removes it for every user.

Results and notes go with their checklist only when it is deleted for good: `Results\delete_with_checklist()`, on
`before_delete_post`, deletes every `uat_result` child in any status, trashed ones included. The trash keeps them,
so restoring a checklist restores its history. It acts on checklists alone, because results saved before
checklists had a post type belong to the page that held the checklist until they are moved. Anything that moves
results must keep their modified dates: the latest result on a step is found by that date, so `wp_update_post()`,
which sets it to now, would reorder every tester's history.

### Access is by email domain, not by role

`inc/access.php` grants two capabilities of the plugin's own from `user_has_cap`. `manage_uat_checklists` goes
to a **UAT manager**: a user whose email is at a domain in `hm_uat_checklist_staff_domains` (default
`humanmade.com`), or one given **Full access to UAT checklists** on their profile. It writes checklists, marks
results fixed and trashes them. `read_uat_results` goes to managers and to anyone with `edit_posts`, so every role
above Subscriber reads the Overview and Results. Saving a result from the panel needs neither: any member can.

Only a user at a staff domain sees or changes the profile checkbox, so access granted by it cannot be passed on.
The domain match is exact and ignores case: `@humanmade.com.example` and `@nothumanmade.com` are not staff.

UAT is a top-level menu owned by `inc/overview.php`, with Overview first. Both post types set `show_in_menu` to
it: as the checklist post type's own menu it would vanish for a reader who cannot edit checklists.

### A step's id is its identity

Results are stored against `stepId`, a UUID assigned when a step is added: by the editor, by the skill's build
for a spec step with no id, and by the `save-checklist` ability. Rewording a step keeps its results. Never
generate ids anywhere else, and never reuse one.

### Abilities are for the MCP adapter, and none records a result

`inc/abilities.php` registers five abilities in the `uat-checklist` category. Each sets `meta.public`, which the
WordPress MCP adapter reads to list it, and `meta.show_in_rest` false, which keeps it off core's
`/wp-abilities/v1` routes. All five are for Human Made only: `Abilities\can_use()` admits a user whose email is at a
staff domain and nobody else. The profile's full-access grant does not open them, and neither does the reading
capability every Contributor holds: those let a client work in wp-admin, not hand the site to an AI assistant.
None saves a Pass or a Fail. A result is a tester's judgement, and one an assistant recorded would read as theirs.

`save-checklist` replaces a checklist's whole content with the sections and steps it is given. A step keeps its
`id`, and so its results, only when that id is already a step of the same checklist. Any other id is refused
rather than replaced, because a mistyped id would otherwise start the step again with no results. A step with no
id gets a new one. The instructions page is left as it was.

### The hub and the guide are found by slug, and both are optional

`Panel\hub_page_id()` and `Panel\guide_page_id()` look up a published page by a filterable slug. Either
returning 0 removes its admin bar link, and nothing else depends on them.

## The skill

`.claude/skills/creating-a-uat-page/` builds checklists, and optionally a UAT page for each, from JSON specs. It
is for use from a site project's root, pointed at this checkout's scripts. `SKILL.md` is the procedure;
`scripts/config.py`, `scripts/lib.mjs` and `scripts/config.sh` read the same environment variables for Python,
Node and shell.

- **A spec's checklist becomes a checklist post with the spec's slug**, found again by that slug on every
  rebuild, so its results stay attached. With a page, the page is built first and named as the instructions
  page. `--checklist-only` builds the checklist alone and leaves its instructions page as it was.
- **The host is unknown, so WP-CLI comes from `UAT_WP`**, a command prefix such as `ddev wp` or
  `npx wp-env run cli wp`. `build-page.py` sends each request as JSON on stdin to `wp eval`, because a
  screenshot is too large for an argument. Every prefix the skill documents passes stdin through.
- **Screenshots upload through WP-CLI when it can, and by hand when it cannot.** `--no-upload` uses uploads
  already in the Media Library under their `uat-<slug>-<name>.png` names.
- **The site's locale decides the spec's language.** `build-page.py` and `verify-page.mjs` refuse a mismatch.
- **The scratch page is never empty.** An empty page opens **Choose a pattern** over the editor on a theme with
  starter patterns, and the screenshot script cannot dismiss it reliably.

The skill is invoked on request only (`disable-model-invocation: true`). Run it with `/creating-a-uat-page`.

## Environment constraints

**WordPress 7.1 or later.** `.wp-env.json` pins core to the `WordPress/WordPress#7.1.3` tag, the oldest minor
`Requires at least` promises. Raise the tag by hand at each 7.1 point release.

**The MCP adapter is a wp-env plugin, not a dependency.** `.wp-env.json` installs the
[WordPress MCP adapter](https://github.com/WordPress/mcp-adapter) from its pinned release zip, so the abilities
can be tried through MCP locally. The plugin works without it. A release zip, not the repository, because the
repository has no `vendor/`. `npm run env:start -- --update` fetches a new pin.

**PHP 8.4.** Declared in four places that move together: the plugin header's `Requires PHP`,
`composer.json`'s `config.platform.php`, `.phpcs.xml`'s `testVersion`, and `phpstan.neon.dist`'s `phpVersion`.
`php.yml` and `tests.yml` set the same version for CI.

**WP-CLI is stubbed for PHPStan.** `stubs/wp-cli.php` declares the WP-CLI calls `inc/cli.php` makes.

## Conventions

- Tabs for indentation. Spaces in `.yml`.
- `declare( strict_types = 1 );` at the top of every PHP file, then the file's namespace.
- **One namespace per file, named after the file.** `inc/namespace.php` is the root `HM\UAT_Checklist`; every
  other `inc/*.php` declares a sub-namespace matching its filename: `styles.php` → `HM\UAT_Checklist\Styles`.
  A block's `render.php` declares `HM\UAT_Checklist\Blocks`, and `.phpcs.xml` excludes `blocks/` from the
  namespace-directory sniff for that reason.
- **`inc/`, not `includes/`.** `HM.Files.NamespaceDirectoryName` errors with `NoIncDirectory` on namespaced code
  anywhere else.
- Every module's hooks are added in its own `bootstrap()`, never at file scope, so requiring a module has no
  side effects. Named functions in hooks, never closures: a closure cannot be unhooked.
- Hook callbacks are strings, so nothing checks them at parse time. A callback in another module needs its
  full path: `'HM\\UAT_Checklist\\Results\\can_record'`.
- Conditions read subject first: `$title !== ''`, never `'' !== $title`.
- Text domain `uat-checklist`, matching the slug.
- `defined( 'ABSPATH' ) || exit;` after the namespace and any `use` imports, in every file.
- Comments explain *why*, particularly where a simpler-looking alternative is wrong.
- No inline `style` attributes in markup. Values that belong to the theme come from tokens.

`.claude/rules/` holds the path-scoped rules for PHP and for formatting ownership; each loads when a matching
file is read.

## Versioning

**Bump the version once per branch, on the first change that ships, and never again on that branch.**

| Where                      | Form                          |
| -------------------------- | ----------------------------- |
| `uat-checklist.php:6`   | ` * Version:           x.y.z` |
| `uat-checklist.php:26`  | `const VERSION = 'x.y.z';`    |
| `readme.txt:7`             | `Stable tag: x.y.z`           |
| `readme.txt` changelog     | a new `= x.y.z =` heading     |
| `package.json:3`           | `"version": "x.y.z"`          |

Each block's `block.json` carries a `version` too. It is not part of this set: `Blocks\version_styles()`
replaces it with the build hash.

A bump also changes the POT's `Project-Id-Version`, which `bin/i18n pot` reads from the plugin header. Run it
after the bump, or the Translations check fails.

Whether this branch has bumped already is answered by git: compare `git show main:uat-checklist.php` with the
working tree. `npm run check:version` reads committed, modified and untracked changes, and decides from
`.gitattributes` plus the `COMPILED_ROOTS` list (`src` and `blocks`) whether any of them ship. A change that
ships nothing gets no bump. `AGENTS.md`, `CLAUDE.md` and `.claude/` ship, so an edit to any of them needs one.
Patch for fixes, minor for new behaviour, major for a break.

## Releases

`build/` is gitignored on `main` and force-added to the `release` branch by `build-release-branch.yml`, after
`.github/strip-dev-files.sh` has removed everything `.gitattributes` export-ignores. `tag-and-release.yml`
tags `release`, builds the zip with `git archive --prefix=uat-checklist/`, and checks it holds
`uat-checklist.php`, `build/panel.js` and `build/blocks/checklist/block.json`. A zip without `build/` installs
cleanly and does nothing.

`languages/` ships, compiled. `src/` and `blocks/` are export-ignored and still ship, compiled into `build/`.

`AGENTS.md`, `CLAUDE.md` (a symlink to it) and `.claude/` ship as they are, so the agent instructions and the
creating-a-uat-page skill come with the installed plugin. `docs/`, `PRODUCT.md` and `.impeccable/` are
export-ignored and do not ship. `.gitattributes` and `.distignore` list the same paths and change together.

## Commands

| Command                 | What it does                                                                          |
| ----------------------- | ------------------------------------------------------------------------------------- |
| `composer install`      | Installs the HM coding standard, PHPStan and the WordPress stubs                      |
| `composer lint`         | PHPCS over `inc/`, `blocks/` and the main file, then PHPStan level 5                  |
| `npm run build`         | Compiles to `build/`, and writes `build/i18n-map.json`                                |
| `npm start`             | The same, in watch mode                                                               |
| `npm run lint:js`       | ESLint over `src/`, `blocks/`, `tests/e2e/`, webpack's config and the skill's scripts |
| `npm run lint:style`    | Stylelint over `src/` and `blocks/`                                                   |
| `npm run lint:md`       | Markdownlint over `README.md`, this file and `.claude/`                               |
| `npm run lint:pkg-json` | npm-package-json-lint over `package.json`                                             |
| `bin/i18n`              | Regenerates the POT, merges it into each catalog and compiles them; needs a build     |
| `bin/i18n check`        | Fails when the POT no longer matches the code or a catalog does not compile           |
| `npm test`              | The hermetic version suites, as CI runs them                                          |
| `npm run check:version` | Whether this branch ships a change that needs a version bump                          |
| `npm run env:start`     | Boots `@wordpress/env` on port 3034 with Query Monitor                                |
| `npm run test:wp`       | The WordPress suite in `tests/wp/`, inside a running wp-env. Needs `npm run build`    |
| `npm run test:e2e`      | The browser suite in `tests/e2e/`, on WordPress Playground. Needs `npm run build`     |

Run every gate before a commit: `composer lint`, `npm run lint:js`, `npm run lint:style`, `npm run lint:md`,
`npm run lint:pkg-json`, `npm run build`, `bin/i18n check`, `npm test`, `npm run test:wp` and `npm run test:e2e`.

### Three kinds of test

`tests/test-version.php` and `tests/test-version-bump.sh` are plain PHP and bash, with no WordPress, and run in
seconds.

`tests/wp/` needs a real WordPress, because its answers depend on core: what `wp_get_global_styles()` returns
for a theme, how a REST permission resolves, what a saved result looks like. It runs through
`wp eval-file tests/wp/run.php` with the same `check()` style as the hermetic suites, not PHPUnit. Each test
creates and deletes its own pages and users and removes every filter it adds, so the suite passes on a site
with content in it. It also unschedules every cron event it schedules, through `hm_test_unscheduling()`, never by
clearing a whole hook, which would drop the site's real emails.

`tests/e2e/` is Playwright in Chromium, for the panel's JavaScript, which neither of the others runs: the step
form, moving on after a save, the open state the panel keeps, the pop-out, and step IDs in the editor. Playwright
boots WordPress Playground on port 9434 from `tests/e2e/blueprint.json`, with the WordPress and PHP versions read
from `.wp-env.json`, and reuses a server already running there outside CI. The blueprint adds a manager at a staff
domain and a Subscriber tester, both with the password `password`. Each test publishes its own checklists through
the `site` fixture in `tests/e2e/utils.js`, which deletes them, and so their results, afterwards. It runs one worker: every test writes to
the one site, and running them in parallel has not been tried.
