# UAT spec format

<!-- Last updated: 2026-10-03T10:00+10:00 -->

One JSON file per block, at `docs/uat/<slug>.json` in the site's repository. `examples/call-to-action.json` is
the worked example: read it before writing a new one.

A spec builds two things. Its `checklist_intro` and `checklist` become a checklist post with the slug `<slug>`.
Everything else becomes the page about the block, which is named as the checklist's instructions page. With
`--checklist-only`, a spec needs only `slug`, `title`, `language` and `checklist`, and the page's keys are
ignored.

## Top level

| Key               | Holds                                                                                                  |
| ----------------- | ------------------------------------------------------------------------------------------------------ |
| `slug`            | The block's directory name, singular: `breadcrumb`, `card-carousel`. The page URL is `<pages>/<slug>/`. |
| `language`        | The site's language code, from `site-info.sh`: `"en"`, `"ja"`, `"de"`. Defaults to `"en"`.             |
| `title`           | The block's title as the admin shows it. It is the page title, the panel heading and the picker entry. |
| `subtitle`        | One sentence under the title: what the block is for.                                                   |
| `tutorial`        | Content nodes under the first heading, How to use the block.                                           |
| `examples`        | Content nodes under Live examples. Real block markup goes here as `block` nodes.                      |
| `checklist_intro` | The checklist's intro, above its first section: the ticket line. `p` nodes only.                     |
| `checklist`       | The checklist's steps: a list of sections, each `{ "section": "<title>", "steps": [ … ] }`.           |
| `headings`        | Replaces a page heading, by key: `{ "tutorial": "Steps" }`. Required, with both keys, for a language the builder has no headings for. |

The builder writes the page's two `h2` headings itself, in that order, so a block page never contains them. It
knows them in English and Japanese.

## The hub

`templates/hub.json` sets `"hub": true` and builds at the top of the site, at `/<slug>/`. It holds `slug`,
`title`, `subtitle` and `content`, a list of content nodes. It has no checklist and no fixed headings, so it
uses `h2` nodes for its sections. Every other page builds under it while it is published and its slug is
`UAT_HUB_SLUG`.

## Reference pages

A page of reading matter for testers, such as a glossary, sets `"reference": true`. It takes the hub's keys and
builds where a block page would, with `content` held to the guide's reading measure and no checklist. The
verifier refuses it, as it does the hub: request each of its links and expect a 200.

A guide with a practice checklist, such as `templates/using-the-checklist.json`, is a block page instead, with
`headings` renaming its sections.

## Content nodes

Each node is an object with one key naming its type. Text is HTML: `<strong>`, `<em>` and `<a href>` are fine.

| Node                                                          | Becomes                                                 |
| ------------------------------------------------------------- | ------------------------------------------------------- |
| `{ "h2": "How testing works" }`                               | A level 2 heading, for the hub                          |
| `{ "h3": "Settings" }`                                        | A level 3 heading                                       |
| `{ "p": "…" }`                                                | A paragraph                                             |
| `{ "ul": [ "…", "…" ] }` / `{ "ol": [ … ] }`                  | A bulleted or numbered list, one string per item        |
| `{ "image": "editor-settings", "alt": "…", "caption": "…" }`  | A full-size image, not linked                           |
| `{ "images": [ { "image": …, "alt": …, "caption": … }, … ] }` | Images side by side, one per column; they stack on a phone |
| `{ "steps": [ { "text": "…", "image": …, "alt": … }, … ] }`   | Numbered steps, each with an optional screenshot under its text |
| `{ "block": "<!-- wp:… /-->" }`                               | Markup inserted as written                              |

An `image` names a screenshot at `<cache>/shots/<slug>/<name>.png`, made by `screenshot.mjs`. The builder
uploads it as `uat-<slug>-<name>.png` and uploads again only when the file has changed. With no file on disk,
or with `--no-upload`, it uses the upload of that name already in the Media Library. `alt` describes what the
image shows; `caption` is optional and says what to notice in it. Use `images` for two or three `crop` shots
of one small control, before and after a click.

**`"float": "right"`** sets a narrow screenshot (a sidebar panel, a popup, a toolbar) beside the text, at up to
45% of the column, with the text wrapping round it. It drops to full width under 600px. Text wraps only round
a float that comes first, so a floated `image` node goes before the paragraph it sits beside. Leave a wide shot
unfloated. A heading after a float starts below it. The plugin's `src/guide.scss` styles both classes.

**`steps`** is the how-to: one numbered step per action, each with the screenshot of what the reader sees at
that point, on the line under its text. A step's screenshot is never floated. A step without one leaves
`image` out.

A checklist step can carry a screenshot too, above its instructions: add `image` and `alt` to the step, for a
control a tester could not find from the words alone.

## Placeholders

The builder replaces these, so one spec builds on any environment.

| Placeholder          | Becomes                                                                      |
| -------------------- | ---------------------------------------------------------------------------- |
| `{site}`             | The site's path: `{site}/newsroom/`                                          |
| `{pages}`            | The path UAT pages sit under: the hub's, or the site's when there is no hub  |
| `{media:<slug>}`     | The ID of the attachment with that slug, for a block's `imageId`             |
| `{media-url:<slug>}` | That attachment's URL                                                        |
| `{post:<slug>}`      | The ID of the published post with that slug, for a block that stores one     |
| `{design:<key>}`     | A design link from `design-links.json`; see below                            |

Link another UAT page as `{pages}/<slug>/`, so the link holds whether or not the site has a hub.

## Steps

A single check is one action and one result:

```json
{ "id": "…", "step": "What the tester does.", "expected": "What they should see." }
```

A scenario is a short title, numbered instructions, and a list of results. The tester gives the whole scenario
one Pass or Fail:

```json
{
	"id": "…",
	"step": "Add the block and save it",
	"instructions": ["Create a new page and close <strong>Choose a pattern</strong>.", "…"],
	"expected": ["What a setting visibly changes.", "After the reload, no invalid-content message."]
}
```

`expected` is a list only when it holds two results or more. The panel shows the instructions under the step's
title, the results as a numbered list, and a line telling the tester to fail the step and name the result in
the comment. One result is a string, because Prettier and the builder disagree on how to write a one-item list.

### Design links

A design step links its frame with a token, never a URL:

```json
{ "step": "Compare your draft's preview with the <a href=\"{design:desktop}\">desktop design</a>.", "expected": "…" }
```

The builder fills `{design:desktop}` and `{design:mobile}` from `design-links.json` beside the spec, one set
per language:

```json
{ "call-to-action": { "en": { "desktop": "https://www.figma.com/design/…", "mobile": "…" } } }
```

A person adds the links when they have them. Until then the step reads "(design link not added yet)"; that is
not a fault, and nothing checks for it. A value that is not an `https://` link, or holds a space or a quote,
stops the build.

### Required and nice-to-have steps

Every step is required unless the spec marks it `"optional": true`. The builder writes `"required": false` on
that step's block. The panel tags the other steps **Required**, and its count reads "4 of 9 required done, 6
of 14 in all". SKILL.md's checklist rules say which steps are nice to have.

### Ids

Leave `id` out of a new step. The builder gives it one and saves the spec, and that id is what the step's
results are stored against: commit it, and never change or reuse it. Rewording a step keeps its results;
deleting the step leaves them in **UAT results**, reading as the step did when it was saved.
