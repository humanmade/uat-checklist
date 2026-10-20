---
name: creating-a-uat-page
description: Create or update a block's testing checklist, and optionally its UAT page, on a site running the uat-checklist plugin. Detects the site's language, writes the spec in it, takes the editor screenshots with Playwright, builds the checklist post and the page through WP-CLI, and verifies them as a test user.
disable-model-invocation: true
---

# Creating a block's checklist and UAT page

<!-- Last updated: 2026-10-20T13:38+10:00 -->

A block's **testing checklist** is a checklist post, which testers work through from the panel the plugin adds
to the admin bar, on any screen. A UAT page is optional: where a client learns the block, it holds how to use
the block and live examples, and it is linked to the checklist as its instructions page. Both are built from one
JSON spec, so they can be rebuilt on any environment and reviewed like code.

`examples/call-to-action.json` is the worked example. `references/example-project.md` shows how one project
applied these rules to its own theme.

## What it produces

| Thing       | Value                                                                                         |
| ----------- | --------------------------------------------------------------------------------------------- |
| Spec        | `docs/uat/<slug>.json` in the site's repository, committed                                    |
| Checklist   | A published checklist post with the slug `<slug>`, listed in the panel under the spec's title |
| Page        | `<pages>/<slug>/`, unless `--checklist-only`: under the hub page when there is one            |
| Screenshots | `<cache>/shots/<slug>/*.png`, uploaded to the Media Library as `uat-<slug>-<name>.png`        |
| Visibility  | Yoast's noindex on the page when Yoast is active; never added to a menu                       |

The page is the checklist's instructions page, so the panel links to it and opens that checklist there. The
build prints the page's URL and the checklist's edit screen:

```text
Page 85: http://localhost:8888/hub/buttons/
Checklist 86: http://localhost:8888/wp-admin/post.php?post=86&action=edit
```

`<cache>` is `~/.cache/hm-uat/<site>/`, one folder per `UAT_URL`, so two sites never share screenshots.

## Setting up

Run every command from the site project's root, where `docs/uat/` lives and where the local environment's
config sits. Point `S` at this skill's scripts in your checkout of uat-checklist:

```sh
S=~/work/uat-checklist/.claude/skills/creating-a-uat-page/scripts
export UAT_URL=http://localhost:8888/          # the site's home URL, a network site's path included
export UAT_WP="npx wp-env run cli wp"           # how WP-CLI reaches it; see the table below
```

| Host                   | `UAT_WP`                                         |
| ---------------------- | ------------------------------------------------ |
| WP-CLI on this machine | `wp` (the default)                               |
| wp-env                 | `npx wp-env run cli wp`                          |
| DDEV                   | `ddev wp`                                        |
| Lando                  | `lando wp`                                       |
| VIP dev-env            | `vip dev-env exec --slug <slug> --quiet -- wp`   |

The command has to pass stdin through, because screenshots reach WordPress that way; all five above do.
Then, once per checkout and once per site:

1. `npm ci` in the uat-checklist checkout, which installs Playwright.
2. `npx playwright install chromium` in the same checkout.
3. `$S/ensure-test-users.sh`, which creates `uattestbot` (Editor) and `uattestadmin` (Administrator) and gives
   both **Full access to UAT checklists**: opening a checklist in the editor takes a UAT manager.

The scripts sign in as those two users only, so a check never overwrites a real tester's results.
`ensure-test-users.sh` refuses a host that does not look local unless `UAT_ALLOW_REMOTE=1` is set.

| Variable              | Default            | Set it to                                                         |
| --------------------- | ------------------ | ----------------------------------------------------------------- |
| `UAT_HUB_SLUG`        | `hub`              | The hub page's slug, or empty for a site with no hub              |
| `UAT_SCRATCH_CONTENT` | an empty paragraph | Block markup every editor shot starts from, such as a title band  |
| `UAT_HIDE`            | `#vip-non-prod-bar` | Selectors of banners a local environment draws over the editor   |
| `UAT_CACHE`           | per site           | Another folder for screenshots and the test users' passwords      |

## Scripts and references

| Script                                  | Run it to                                                                   |
| --------------------------------------- | --------------------------------------------------------------------------- |
| `site-info.sh`                          | Print the site's language, theme, plugin state and hub page. Writes nothing |
| `check-prose.py <spec>`                 | Lint a spec's wording. Exits 1 until it is clean                            |
| `screenshot.mjs <slug> <name> <kind> …` | Capture one screenshot. Its file comment lists every kind                   |
| `check-pattern.mjs <search> "<title>"`  | Walk a pattern test in the browser and print what each step shows           |
| `build-page.py <spec>`                  | Build one checklist and its page. `--dry-run` prints the markup              |
| `verify-page.mjs <spec>`                | Check one built checklist and page as the test user. Saves nothing          |
| `clean-drafts.sh [--delete]`            | List, or delete, the test administrator's leftover drafts                   |
| `ensure-test-users.sh`                  | Create the two test users. Safe to run every time                           |

Read these when the step calls for them:

- `references/spec-format.md`: the spec's keys and content nodes, and the `{site}`, `{pages}` and `{media:…}`
  placeholders.
- `references/editor-ui.md`: core editor labels and behaviour in English and Japanese, checked in a browser.
- `references/screenshots.md`: which kind to use, how to write a `crop`, and what a failed crop means.
- `references/example-project.md`: one project's page set, page rules and screenshot recipes, as a model.

## The site's language decides the page's

Run `$S/site-info.sh` before writing anything. Its **Locale** line is the language every page on that site is
written in: title, text, headings and every step. Write the locale's language code in the spec as
`"language"`, such as `"ja"` for `ja`. The builder and the verifier refuse a spec whose language does not match
the site.

Quote interface labels exactly as that site's admin shows them. Look them up in the site's language pack under
`wp-content/languages/` or in the browser, never translated from English yourself. The builder has section
headings for English and Japanese. For any other language, give the spec a `headings` object with all three.

A site in a second language gets its own specs, built against its own `UAT_URL`, with every screenshot taken
again there so the admin shows that language.

## Procedure

1. **Read the block, not a description of it.** Its `block.json` (title, attributes, `supports`, `parent` and
   `allowedBlocks`), its `edit.js` (every control in the toolbar and the inspector), its render code (what the
   front end shows, and when it shows nothing), its patterns, and where templates and parts use it:
   `grep -rn 'wp:<namespace>/<slug>' <theme>/{templates,parts,patterns}`. A hit in a template or part means the
   page needs a Site Editor section. Note `supports.multiple`: a block allowed once per page appears once in
   the examples, and its add scenario expects the inserter to grey out a second copy.
2. **Write the spec** following `references/spec-format.md` and the page rules below, in the site's language.
   Copy the shape of `examples/call-to-action.json`. Quote every label from `references/editor-ui.md`, and see
   anything it does not cover in the editor before a step expects it. For a pattern, `check-pattern.mjs`
   prints the notice, the field to rewrite and whether the change survives a reload. Then check the wording:

   ```sh
   python3 $S/check-prose.py docs/uat/<slug>.json
   ```

   It flags dashes, inflated words, stock phrases, long sentences in English and Japanese, the word
   "tutorial", and a Mac shortcut with no Windows one. It exits 1 until they are gone.

3. **Capture a screenshot for every how-to step**, cropped to what that step is about: the block and its
   toolbar, one sidebar panel, the open popup. Show the whole editor only where the reader needs to see where
   something sits. `references/screenshots.md` has the kinds and the crop rules. Open each image and look at it
   before the spec uses it: a capture can succeed and still show the wrong thing.
4. **Build the checklist and the page.** Where they already exist, run `--dry-run` first and diff its markup
   against the live page and checklist. Then build them for real:

   ```sh
   python3 $S/build-page.py docs/uat/<slug>.json
   ```

   The build gives new steps an id and saves the spec. Commit those ids. A rebuild finds the checklist by its
   slug, drafts included, and updates it, so its results stay with it.

5. **Verify them** as the test user, which saves no results:

   ```sh
   node $S/verify-page.mjs docs/uat/<slug>.json
   ```

   Then look at the live examples at 1440px and 390px wide, and correct every expected result that does not
   match. A FAIL in the page's own content (a dead link, a missing screenshot, an invalid block) is yours to
   fix in the spec. A FAIL in the block, the theme or the plugin is a finding: report it with the verifier's
   line rather than changing that code as part of this task.
6. **Link it from the hub**, when the site has one, under its group, and rebuild the hub. The verifier does not
   check a hub, so request each of its links and expect a 200.
7. **Clean up** with `$S/clean-drafts.sh --delete`. A stopped screenshot or a probe in the editor leaves a
   draft behind.
8. **Report** the page URL, the checklist's edit screen, the verifier's output, and every `[TO CONFIRM: …]`
   left in the spec.

## A checklist without a page

Not every site documents its blocks on pages. `--checklist-only` builds the spec's checklist alone, and a spec
for it needs only `slug`, `title`, `language` and `checklist`, with `checklist_intro` optional:

```sh
python3 $S/build-page.py docs/uat/<slug>.json --checklist-only
node $S/verify-page.mjs docs/uat/<slug>.json --checklist-only
```

It uploads only the screenshots the steps use, and never touches a page. A checklist that already names an
instructions page keeps it, so a page built earlier stays linked.

The verifier skips every page check: the page loading, its search visibility and menus, and its images and
links. It still opens the checklist in the panel and checks its steps, the pop-out link and every step's
screenshot, and that the checklist opens in the editor with no invalid block. Procedure steps 1, 2, 3 and 7
apply as written; the page rules below do not.

The hub and reference specs have no checklist, so the builder refuses them with `--checklist-only`.

## When the screenshots cannot be uploaded for you

The builder uploads screenshots through WP-CLI. Where `UAT_WP` can build pages but cannot write files, or the
upload goes to a host you reach some other way, upload them yourself:

1. Take the screenshots as usual. Each is in `<cache>/shots/<slug>/<name>.png`.
2. Upload each to the site's Media Library, renamed `uat-<slug>-<name>.png`.
3. Build with `--no-upload`. It uses the uploads by name, and stops listing any that are missing.

With no WP-CLI access at all, `--dry-run` prints the page's block markup. Paste it into the code editor of a
new page whose slug is the spec's `slug`. The screenshots' `{media:…}` placeholders then need resolving by hand.

## A hub page, and the guide to the panel

Both are optional. Without a hub, pages build at the top level and the admin bar shows no hub link.

- **Hub.** Copy `templates/hub.json` to `docs/uat/hub.json`, fill its `[TO CONFIRM: …]` lines, and build it
  first: the other pages go under it. It lists every page, grouped. The plugin links to it from **UAT** in the
  admin bar while its slug matches `UAT_HUB_SLUG`; the `hm_uat_checklist_hub_slug` filter sets that slug.
- **Guide.** `templates/using-the-checklist.json` explains the panel to testers, step by step. Build it with
  the slug `using-the-checklist`, and the admin bar links to it as **How to use the checklist**. Its
  screenshots are `panel` shots taken on a UAT page of the same site, where the panel opens on that page's
  checklist.

Translate either template into the site's language before building it.

## Page rules

The page is about the block, not the checklist. It has two parts in this order, and the builder writes their
headings: **How to use the block** and **Live examples**, or the site language's equivalents. Never call the
first part a tutorial, in any language. The checklist links to the page as **Open the instructions**, so the
page needs no link back.

**How to use the block.** What the block is for, in one or two sentences. Where it already appears, when
templates or other blocks draw it, because an editor who does not know that inserts a second copy. Then the
how-to, as `h3` topics each holding a `steps` node: one numbered step per action, each with the screenshot of
what the reader sees at that point. Cover inserting it (the inserter tile, cropped and outlined, and the `/`
command with a term you have seen the editor match), filling it in, each setting under the inspector's own
labels, and saving. Then the child blocks it accepts, and what each changes in how the parent looks. Add a Site
Editor section when a template or part uses it.

A screenshot outside a `steps` list may float beside its paragraph (`"float": "right"`) when it is narrow, such
as one sidebar panel. A step's screenshot never floats. Screenshots are never links.

**Patterns.** Where a theme pattern holds the block, a topic says what a pattern is, when to use one (a section
like a live page's, keeping its layout and amount of text) and when to build by hand, and lists the patterns.
Its steps are screenshots too: the inserter's **Patterns** tab with the theme's category open and the pattern
outlined, the locked pattern's sidebar, the "Back … · Pattern" bar, and **Exit pattern**. Adding the block by
hand keeps its own topic; the pattern is never the only route. `references/editor-ui.md` describes the lock.

**Small controls.** A control a tester could miss (a + at the end of a row, a link popup, a toolbar button
with only an icon) gets a `crop` of that area, before and after the click, side by side in an `images` node.
The checklist step that uses the control carries the same screenshot (`image` and `alt` on the step), which
the panel shows above the instructions.

**Live examples.** The block rendered on the page in its main variations, as real `block` nodes: each colour,
with and without an image, empty and long text. Link to the other places it renders on the site. Name each
example with an `h3` node above it, never a paragraph ending in a colon, so a reader can jump between them.

## Checklist rules

Keep the step count down. A tester marks each step Pass or Fail, so every step has to be worth a result of its
own. Most blocks land between 10 and 16 steps.

- **The intro is the ticket line**, as paragraphs in `checklist_intro`. The builder puts them above the first
  section, and the panel shows them under the checklist's title. Add a sentence only where the tester needs
  something the steps do not say, such as which environment to test on.
- **An inner block that cannot be inserted alone** is tested in the checklist of the block that holds it.
- **Two kinds of step.** Actions that depend on each other share one *scenario* step: a short title, numbered
  `instructions`, and a list of expected results. Checks that can fail on their own get one *single check*
  each. The test is whether one could fail while the other passes. A link can work while the mobile layout
  breaks, so they are two steps. Saving cannot be tested if inserting failed, so they share one.
- **Sections, in this order, skipping any that do not apply:** the editor (the scenarios); the Site Editor
  (only when a template or part uses the block); desktop width; mobile width; accessibility. Testers check
  desktop and mobile widths only.
- **Mark the nice-to-have steps `"optional": true`:** the long-text check and every Site Editor step. Every
  other step is required. The panel tags each required step **Required** and leads its count with them, so a
  tester can finish those and stop.
- **Scenarios test adding and editing.** Add and save; edit the saved block; add from the pattern, when the
  block has one. A block with a workflow of its own may have one more. Testers work on their own draft, so the
  built pages stay as they are.
- **Instructions are short: the action and the label in bold.** "Create a new page and close **Choose a
  pattern**", not the route through the admin bar. Keep a hint only where a control is hard to find:
  "(pencil icon → **Advanced**)". Unlocking a pattern, changing it and exiting is one instruction. Save and
  reload are the last two instructions, each with its shortcut for Mac and Windows: "Mac: Command + S,
  Windows: Ctrl + S".
- **Expected results name only what could go wrong unnoticed.** A setting's visible effect, a restriction
  (heading levels offered, one per page, a maximum), the pattern adding the right content, and no
  invalid-content message after the reload. Not "the text appears where you typed it". Two or three per
  scenario. A scenario with one expected result writes it as a string.
- **One design step at desktop width and one at mobile width,** straight after that width's layout step,
  linking the design frame as `<a href="{design:desktop}">` and `<a href="{design:mobile}">`. A person adds
  the links later; `references/spec-format.md` says where.
- **Accessibility checks are single checks, one to three per block.** Always the 200% zoom check. A Tab and
  Enter check for each kind of interactive part. An Inspect check for a heading level the block lets the
  editor set, when the theme styles headings by class so that levels look alike.
- **Long text is a single check at mobile width,** on the example with the longest title.
- **Link the page a step opens**, with `{site}` and `{pages}` paths, so the tester does not have to find it.
- **Keep each step's id when its meaning stays.** Results are stored against it, so rewording keeps them and a
  new id starts the step from nothing. A scenario that replaces several old steps takes a new id. Never write
  an id by hand: leave a new step's out, and copy an old one whole. Two steps never share an id.

## Things that will bite you

- **Never sign in as a real tester to check a page.** Their results are stored under their user, and a check
  that saves overwrites them. The scripts sign in as the test users for this reason.
- **Delete test results with `wp post delete <id> --force`, never with SQL.** A persistent object cache keeps
  query results, and a row deleted underneath it keeps being counted.
- **A theme whose navigation falls back to a Page List links every published page**, UAT pages included, and
  the verifier fails "not linked from any menu". Give the header a navigation menu that leaves them out.
- **A site that does not discourage search engines, with no Yoast, indexes every UAT page.** The verifier
  fails "hidden from search engines" until Settings → Reading says otherwise or Yoast is active.
- **Refer to media and posts by slug, not ID.** IDs differ between environments. `{media:<slug>}`,
  `{media-url:<slug>}` and `{post:<slug>}` resolve when the page is built.
- **A full-page screenshot leaves lazy images grey.** Nothing scrolls them into view. Before writing that an
  image is missing, check that the `img` is in the markup.
- **The editor canvas scrolls inside its own iframe.** Scrolling the surrounding pane moves nothing, and the
  editor then scrolls the selected inner block into view, which can cut the top off a parent. The verifier's
  "every screenshot loads" does not catch this, so look at each image.
- **A step written from memory of the editor is usually wrong somewhere.** A link's new-tab checkbox is two
  clicks deep, and a pattern is locked until Edit pattern. `references/editor-ui.md` holds what was seen; check
  anything else first.
- **Building a spec against the wrong site replaces that site's page of the same slug.** The language check
  catches a site in another language. Two sites in one language do not differ to it, so check `UAT_URL`.
- **A change to the plugin shows nothing until `npm run build`.** Blocks register from `build/blocks/`, so an
  edited `blocks/step/render.php` keeps rendering the old copy, with no error.
- **A one-item list in a spec fails Prettier.** Prettier writes `["…"]` on one line and the builder saves the
  spec expanded. Write a single expected result as a string.
