// Runs a pattern test the way a tester would and prints what each step shows, so the steps can quote it.
//
//   node check-pattern.mjs <search term> "<pattern title>"
//
// Opens a new page as the test administrator, searches the inserter, clicks the pattern, clicks inside it, clicks
// Edit pattern, types into the first text field, clicks Exit pattern, saves and reloads. The draft it saves is
// deleted before it exits. UAT_URL picks the site, and the pattern title is the one that site's inserter shows.
// The report is JSON on stdout.
import { chromium } from 'playwright';

import {
	ADMIN,
	BASE,
	dismissWelcomeGuide,
	editorPostId,
	login,
	wp,
} from './lib.mjs';

const [search, title] = process.argv.slice(2);
if (!search || !title) {
	console.error('Usage: check-pattern.mjs <search term> "<pattern title>"');
	process.exit(1);
}

const MARK = 'UAT-CHECK';
// The two buttons' labels in English and Japanese; UAT_EDIT_PATTERN and UAT_EXIT_PATTERN add another language's.
const label = (...labels) =>
	new RegExp(`^(${labels.filter(Boolean).join('|')})$`);
const EDIT = label(
	'Edit pattern',
	'パターンを編集',
	process.env.UAT_EDIT_PATTERN
);
const EXIT = label(
	'Exit pattern',
	'パターンを終了',
	process.env.UAT_EXIT_PATTERN
);
const report = {};

const browser = await chromium.launch();
const page = await browser.newPage({
	viewport: { width: 1440, height: 1100 },
	ignoreHTTPSErrors: true,
});
page.on('dialog', (dialog) => dialog.accept());
const canvas = page.frameLocator('iframe[name="editor-canvas"]');
const documentBar = () =>
	page
		.locator('.editor-document-bar')
		.innerText()
		.then((text) => text.replace(/\s+/g, ' '));
const editorState = () =>
	page.evaluate((mark) => {
		const editor = window.wp.data.select('core/block-editor');
		const root = editor.getBlocks().at(-1);
		return {
			hasMark: JSON.stringify(editor.getBlocks()).includes(mark),
			patternName: root?.attributes?.metadata?.patternName ?? null,
			selected: editor.getSelectedBlock()?.name ?? null,
		};
	}, MARK);
let draftId = '';

try {
	await login(page, ADMIN);
	await page.goto(`${BASE}/wp-admin/post-new.php?post_type=page`);
	draftId = await editorPostId(page);
	await dismissWelcomeGuide(page);
	await page.locator('.editor-start-page-options__modal').waitFor();
	await page.waitForTimeout(1200);
	await page.keyboard.press('Escape');

	await page.click('.editor-document-tools__inserter-toggle');
	await page.fill('.block-editor-inserter__search input', search);
	await page.waitForTimeout(2500);
	report.patternsListed = await page
		.locator('.block-editor-block-patterns-list__item-title')
		.allInnerTexts();
	await page
		.getByRole('option', { name: title, exact: true })
		.first()
		.click();
	await page.waitForTimeout(1500);
	report.notice = await page
		.locator('.components-snackbar')
		.first()
		.innerText()
		.catch(() => '');
	await page.click('.editor-document-tools__inserter-toggle');
	await page.waitForTimeout(800);

	// A click on the pattern's first text, where a tester would click.
	const first = canvas
		.locator('.is-root-container > .wp-block')
		.last()
		.locator('[contenteditable="true"]')
		.first();
	const box = await first.boundingBox();
	await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
	await page.waitForTimeout(800);
	report.clickSelects = (await editorState()).selected;
	report.locked =
		(await page.getByRole('button', { name: EDIT }).count()) > 0;

	if (report.locked) {
		await page.getByRole('button', { name: EDIT }).first().click();
		await page.waitForTimeout(1500);
		report.barWhileEditing = await documentBar();
	}

	const field = canvas
		.locator('.is-root-container [contenteditable="true"]')
		.first();
	report.firstField = await field.evaluate(
		(el) => `${el.getAttribute('aria-label')}: ${el.innerText.slice(0, 40)}`
	);
	const fieldBox = await field.boundingBox();
	await page.mouse.click(
		fieldBox.x + fieldBox.width - 3,
		fieldBox.y + fieldBox.height / 2
	);
	await page.keyboard.press('End');
	await page.keyboard.type(MARK);
	await page.waitForTimeout(500);
	report.typed = (await editorState()).hasMark;

	if (report.locked) {
		await page.getByRole('button', { name: EXIT }).first().click();
		await page.waitForTimeout(1200);
		report.barAfterExit = await documentBar();
	}

	await page.keyboard.press(
		process.platform === 'darwin' ? 'Meta+s' : 'Control+s'
	);
	await page.waitForFunction(
		() =>
			!window.wp.data.select('core/editor').isSavingPost() &&
			!window.wp.data.select('core/editor').isEditedPostDirty(),
		null,
		{ timeout: 20000 }
	);
	await page.reload();
	await page.waitForFunction(
		() => window.wp?.data?.select('core/block-editor')?.getBlocks().length
	);
	await page.waitForTimeout(2500);
	const after = await editorState();
	report.afterReload = {
		changeKept: after.hasMark,
		stillAPattern: after.patternName,
		invalidBlockWarnings: await canvas
			.locator('.block-editor-warning')
			.count(),
	};
	process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
} finally {
	await browser.close();
	if (draftId) {
		wp(['post', 'delete', draftId, '--force']);
	}
}
