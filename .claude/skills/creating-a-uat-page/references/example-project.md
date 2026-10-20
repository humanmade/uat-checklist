# Worked example: a bilingual corporate site

<!-- Last updated: 2026-10-02T14:00+10:00 -->

How one project applied SKILL.md to its own theme. Use it as a model for the decisions a new project has to
make, not as rules: every name below belongs to that theme. Its theme namespace is written `my-theme` here.

## Contents

- The sites and their languages
- The page set
- The theme's own rules
- Editor labels the theme added
- Screenshot recipes for the theme's blocks
- What bit that project

## The sites and their languages

Two sites on one multisite network: a Japanese site, which was the client's working language, and an English
one. Testing ran on the Japanese site first. Once a Japanese page was finished, its English spec was written
and built on the English site, with every screenshot taken again there so the admin showed English.

- **Specs lived apart by language**: `docs/uat/<slug>.json` for Japanese and `docs/uat/en/<slug>.json` for
  English, built with `UAT_URL` set to each site in turn.
- **The English spec kept the Japanese one's sections in the same order, with the same number of steps.** A
  small script then copied the step ids across by position, so results on the two sites lined up step for step.
- **Each site tested its own language only.** No step on the Japanese site checked English content, and the
  English spec's last section checked long English text in place of the Japanese one's long Japanese text.
- **The scratch page was titled after its site** ("UAT Japanese", "UAT English") with `UAT_SCRATCH_TITLE`,
  and started with the theme's title band through `UAT_SCRATCH_CONTENT`, since every page on the site did.

## The page set

The client's list of blocks fixed the page set. The hub grouped them under Page structure, Cards, Content and
Press releases. A page not on the list was removed; a block added to the list got a page.

Blocks of one family shared a page: a notification bar with its messages, the three card rows with the card
they hold, an organisation chart with its nodes, and three newsroom blocks on one press release query.

Two guides sat beside the block pages in the same format, each with `headings` renaming the page's sections:
writing a press release, and creating a page and adding it to the footer's sitemap menu.

## The theme's own rules

- **A Section band block held most content blocks.** Card carousel, the card rows, an information list and a
  press release list could only go inside it. Each had its own page, and on it the Section and the child were
  always together: in the scenarios, the examples, the front-end checks, the design steps and every
  screenshot.
- **The Section's add scenario** started from the inserter's Section tile, then the empty Section with its +
  clicked and the list of child blocks open, then the Section holding the child. The + offered six of the seven
  children; the press release list was found by typing its name in that list's search field.
- **Two blocks inserted their patterns unlocked.** Their `edit.js` removed the pattern stamp as the block
  landed, so their pages had no unlock step, and their pattern scenario checked that a change survived a
  reload without one.
- **A block that took an icon** had a "Changing the icon" topic: the sidebar's Icon panel, the Icon library,
  and the block after the change. The library was untranslated, so the Japanese step said to search in English.
- **Footer links went in the Sitemap menu's columns.** Only a policy page went in the Policies menu.
- **Headings were styled by class**, so H2 and H3 looked alike. A block that let the editor choose a heading
  level had an accessibility step inspecting it in the browser's developer tools.

## Editor labels the theme added

The `/` terms that listed each block first, in each language. Keep a table like this for your own theme: a
step that names a term nobody has tried is the commonest wrong step.

| Block | Japanese | English | Note |
| --- | --- | --- | --- |
| Section | `/セクション` | `/section` | English also lists core's Heading and Group above the Section patterns |
| Page title | `/ページタイトル` | `/page title` | |
| Breadcrumb | `/パンくず` | `/breadcrumb` | Both list core's breadcrumb block too; the theme's has the ≡ icon |
| Call to action | `/コール` | `/cta` | `/call` and `/action` find nothing |
| Business card | `/ビジネス` | `/business` | |

## Screenshot recipes for the theme's blocks

These ran against that theme, so the selectors are its own. `S` is the scripts folder.

A link popup with its new-tab checkbox open, on the call-to-action block's button:

```sh
node $S/screenshot.mjs call-to-action button-link-tab crop --block my-theme/cta \
  --steps '[{"preference":["core/block-editor","linkControlSettingsDrawer",false]},{"button":"Button link","wait":1200},{"type":"/newsroom/"},{"press":"Enter","wait":1200},{"button":"Edit link","wait":1000},{"button":"Advanced","within":".block-editor-link-control","wait":1000}]' \
  --clip '[{"page":".block-editor-block-contextual-toolbar"},{"page":".components-popover .block-editor-link-control"}]' --pad 10
```

A Section's child list, then the Section holding the chosen child:

```sh
SEC=.wp-block-my-theme-section
node $S/screenshot.mjs section child-list crop --block my-theme/section \
  --steps "[{\"click\":\"$SEC .block-editor-button-block-appender\",\"wait\":1200}]" \
  --clip "[{\"canvas\":\"$SEC .block-editor-button-block-appender\"},{\"page\":\".block-editor-inserter__quick-inserter\"}]" --pad 12
node $S/screenshot.mjs section child-cards crop --block my-theme/section \
  --steps "[{\"click\":\"$SEC .block-editor-button-block-appender\",\"wait\":1200},{\"option\":\"Three column cards\",\"wait\":1500},{\"frame\":true}]" \
  --clip "[{\"canvas\":\"$SEC\"}]" --pad 8
```

Two crop rules came from this theme: clip a Section to `.section__inner`, which holds its header, image and
child, since the Section itself is full width; and clip a list of press releases by its last item, since the
list's box was shorter than the rows in it. The pilot page's 12 shots were kept as data in a small script
beside its spec, in both languages, so they could be taken again in one run.

## What bit that project

- **The first breadcrumb read Home** on the Japanese front end, because it came from an SEO plugin's breadcrumb
  setting. A step expecting the Japanese label failed until that setting was changed.
- **A press release with a PDF redirected to it**, so a step that opened it started a download. Steps linked
  only press releases without one.
- **The host's object cache kept deleted rows counted.** Test results deleted with SQL still showed in the
  panel's counts; `wp post delete <id> --force` cleared them.
- **Both sites used the same slugs**, so a Japanese spec built against the English site replaced the English
  page. The builder's language check now refuses that.
- **A data sync from production overwrote the network's list of sites**, so the UAT sites were checked after
  every sync.
