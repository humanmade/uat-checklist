# Editor labels and behaviour

<!-- Last updated: 2026-10-02T14:00+10:00 -->

What core's block editor shows testers, in English and Japanese, as seen in a browser on WordPress 7.1.2 with
no Gutenberg plugin. Quote these in steps and expected results. For a site in another language, find each label
in that site's admin before a step uses it. After a core update, check an entry again before relying on it:
`check-pattern.mjs` re-checks the pattern ones in a minute.

A theme adds labels of its own: its block titles, its pattern names, its menus. Record those in the project
as you see them; `example-project.md` shows the kind of table worth keeping.

## Contents

- Opening a new page
- Adding a block with `/`
- Patterns and their lock
- Links and the new-tab checkbox
- Adding a button to a row
- Notices

## Opening a new page

| | English | Japanese |
| --- | --- | --- |
| Admin bar | **+ New** → **Page** | **+ 新規** → **固定ページ** |
| Left menu in wp-admin | **Pages** → **Add Page** | **固定ページ** → **固定ページを追加** |
| Window that opens, when the theme has starter patterns | **Choose a pattern** | **パターンを選択** |
| Empty page | **Add title** | **タイトルを追加** |

Closing the window with × leaves an empty page. Choosing a starter pattern inserts it unlocked.

## Adding a block with `/`

An empty paragraph reads "Type / to choose a block" (「ブロックを選択するには「/」を入力」). The list matches block
titles and keywords in the admin's language, so a Japanese title works in a Japanese admin and an English one
in an English admin. Several blocks can share a word: check which term lists the block first, and say which
tile to pick when it is not first.

Blocks restricted to a parent (`parent` or `ancestor` in `block.json`) are not offered at the top level.

## Patterns and their lock

A theme pattern the inserter offers is inserted with its name recorded, and core then locks it.

| Moment | English | Japanese |
| --- | --- | --- |
| Inserted | "Pattern "…" inserted." | 「パターン「…」を挿入しました。」 |
| Click inside | The whole pattern is selected. The sidebar shows its name, Pattern, its description and **Edit pattern** | Same, with パターン and **パターンを編集** |
| After Edit pattern | Document bar "Back … · Pattern"; inner blocks select normally, with their panels | 「戻る … · パターン」 |
| Leaving | **Exit pattern**; the bar returns to "No title · Page" | **パターンを終了**; 「タイトルなし · 固定ページ」 |

Inside a locked pattern every block is disabled: a click on an inner heading selects the pattern, and typing
changes nothing. Changes made while unlocked stay after Exit pattern, saving and reloading, and apply to that
page only, because a theme pattern is a file. After a reload the pattern is locked again. While a pattern is
being edited, only it can be changed: a block next to it is added after Exit pattern.

A block can opt out of the lock by removing the pattern name from its own metadata as it lands, so its
patterns insert unlocked. Read the block's `edit.js` before writing an unlock step.

## Links and the new-tab checkbox

A block's link control opens core's link popup.

1. A new link shows only "Search or type URL" (「検索または URL を入力」). Type the address and press Enter.
2. The popup then shows the link with **Edit link**, **Remove link** and **Copy link** (**リンクを編集**,
   **リンクを削除**, **リンクをコピー**).
3. **Edit link** reopens the field with **Advanced**, **Cancel** and **Apply** (**高度**, **キャンセル**, **適用**).
4. Under Advanced, **Open in new tab** (**新しいタブで開く**) is a checkbox. After **Apply** the popup shows the
   link with a ↗ beside it.

The editor remembers per user whether Advanced was left open (preference `core/block-editor`
`linkControlSettingsDrawer`), so a step says to open it only if it is closed. The popup's "internal" label is
English in the Japanese admin too.

## Adding a button to a row

The + at the end of a row of buttons, "Add button" (「ボタン を追加」), shows only while the Buttons row is
selected. From a button, the toolbar's first item, "Select parent block: Buttons" (「親ブロックを選択: ボタン」),
selects the row.

## Notices

| Action | English | Japanese |
| --- | --- | --- |
| Pattern inserted | Pattern "…" inserted. | パターン「…」を挿入しました。 |
| Navigation menu saved from the editor | Navigation Menu updated. | ナビゲーションメニューを更新しました。 |
| Block that does not validate | Block contains unexpected or invalid content. | このブロックには、想定されていないか無効なコンテンツが含まれています。 |
| Second copy of a one-per-page block | Not checked | このブロックは一度のみ使用できます。 |

Strings core or a plugin leaves untranslated, such as "internal" in the link popup or Yoast's "Readability
analysis", come from neither the theme nor this plugin, so neither is a finding for a UAT page.
