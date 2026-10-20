# Screenshot recipes

<!-- Last updated: 2026-10-02T14:00+10:00 -->

The file comment in `scripts/screenshot.mjs` lists every kind and option. Each command writes
`<cache>/shots/<slug>/<name>.png`, where `<cache>` is the folder for the site `UAT_URL` names. Open every image
before a spec uses it.

## Contents

- Choosing a kind
- Writing a crop
- Recipes
- When a crop fails

## Choosing a kind

| Shot | Kind |
| --- | --- |
| The inserter's search results, a tile outlined | `inserter --search … --outline <block>` |
| A whole block with its settings in the sidebar | `block --block <block>` or `--pattern <pattern> --select <block>` |
| A small control, before or after a click | `crop` |
| A pattern's locked state | `crop --pattern <pattern> --as-pattern`, clipped to the sidebar |
| A template in the Site Editor | `template --template <slug> --block <block>` |
| A navigation menu in the Site Editor | `navigation --menu <wp_navigation slug>` |
| The Testing checklist panel on a built page | `panel --path <site path>` |
| A post being written, the admin menu, a list screen or a Site Editor route | `admin --start new:post`, `edit:<post slug>` or `url:<path>` |

Editor shots start from a scratch draft holding `UAT_SCRATCH_CONTENT`, an empty paragraph by default. Set it
to a theme's title band, for example, when every page on the site opens with one. It is never empty, because an
empty page opens **Choose a pattern** over the editor on a theme with starter patterns.

`example-project.md` has recipes for a theme's own blocks, including a link popup with its new-tab checkbox.

`admin` runs the same steps and clip as `crop`, plus two that set up a post without saving it: `edit` changes
the open post's fields and `insert` adds blocks from markup. `edit:<post slug>` opens a published post. Never
click **Publish** or **Update** there: the change goes live.

## Writing a crop

A crop inserts the block, selects it, fits the window to it, runs `--steps`, then keeps the box around every
`--clip` target plus `--pad` pixels.

- **Name targets by class.** A CSS attribute selector puts double quotes inside the JSON and breaks it. Blocks
  carry `.wp-block-<namespace>-<name>` in the editor, such as `.wp-block-my-theme-section`.
- **Clip to the control, not the row.** A row spans the canvas, so its crop is about 1,000px wide and unreadable
  at half width in an `images` pair. Clip to the one element and the toolbar.
- **Include the parent selector** (`.block-editor-block-parent-selector`) with the toolbar, or the crop cuts a
  button in half at its left edge.
- **Use `frame` after a step that grows the block**, such as giving a container its inner block, or the bottom
  is cut off under the meta box bar.
- **Skip `frame` when the window is already big enough.** It refits the window to the selected block and can
  shrink it, cutting off a crop that fitted before.
- **Close the link drawer first** with `{"preference":["core/block-editor","linkControlSettingsDrawer",false]}`
  when a shot clicks Advanced, since the editor remembers it per user.
- **Scope a button with `within`** when the screen has two of the same name: English has Advanced in both the
  link popup and the inspector.
- **Reach outside the canvas** with `clickPage` (the inserter toggle, a tab), `outline` (a red frame on an
  inserter tile or a pattern preview) and `hide` (a tooltip left over from a click).
- **The inserter's tabs and categories are `tab`s, not buttons.** Click them with
  `[role=tab]:has-text("Patterns")`, which `button` cannot find. Clip the tab row with
  `.block-editor-tabbed-sidebar__tablist-and-close-button`.
- **A pattern far down its category is below the fold** even in a tall window. Search for it in the Patterns
  tab instead, and say in the step that the category lists it too.
- **A toolbar's block switcher is a button named after the block**, scoped with
  `"within": ".block-editor-block-contextual-toolbar"`.
- **Clip a list by its last item, not the list.** A `ul` box can be shorter than the cards in it.
- **Clip a full-width block to its inner wrapper.** Clipping the block itself takes in the editor's edge.

For a page with many shots, keep the commands as data in a small script beside the spec, so the shots can be
taken again after a design change.

## Recipes

`S` is the scripts folder, as in SKILL.md. Labels here are the English ones; use the site admin's own.

The inserter, searched and the tile outlined:

```sh
node $S/screenshot.mjs buttons add-block crop --steps '[{"clickPage":".editor-document-tools__inserter-toggle","wait":1500},{"clickPage":".block-editor-inserter__search input"},{"type":"buttons","wait":2000},{"outline":".editor-block-list-item-buttons"}]' \
  --clip '[{"page":".editor-document-tools__inserter-toggle"},{"page":".block-editor-inserter__search"},{"page":".editor-block-list-item-buttons"}]' --pad 12
```

One sidebar panel for a block:

```sh
node $S/screenshot.mjs buttons settings crop --block core/buttons --steps '[]' \
  --clip '[{"page":".block-editor-block-inspector"}]' --pad 4
```

A locked pattern's sidebar, with the theme's pattern and block names in place of `my-theme/…`:

```sh
node $S/screenshot.mjs call-to-action pattern-edit crop --pattern my-theme/call-to-action --as-pattern \
  --select my-theme/call-to-action --steps '[]' --clip '[{"page":".block-editor-block-inspector"}]' --pad 4
```

The Testing checklist panel with its first step open, for the guide to the panel:

```sh
node $S/screenshot.mjs using-the-checklist step-open panel --path /hub/buttons/ \
  --steps '[{"open":1}]' --clip '[{"page":".uat-panel"}]'
```

## When a crop fails

The error names the step it was waiting on.

| Waiting for | Cause |
| --- | --- |
| A toolbar button after a `type` step | Typing hid the toolbar. The crop moves the mouse after typing; if a shot still fails, add a `wait` |
| A button that exists | A hidden one of the same name came first. Add `within` |
| An `option` | The quick list is still opening. Raise the previous step's `wait` |
| A `clip` target | The control is not on screen: the step before it closed a popup, or the block needs `frame` |

A crop that was stopped leaves its scratch draft behind. `clean-drafts.sh` lists it, and `--delete` removes it.
