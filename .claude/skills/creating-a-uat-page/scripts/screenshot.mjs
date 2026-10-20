// Captures one screenshot for a UAT page into <cache>/shots/<slug>/<name>.png. lib.mjs says where <cache> is.
//
//   node screenshot.mjs <slug> <name> inserter  --search <term> [--outline <block-name>]
//   node screenshot.mjs <slug> <name> block     --block <block-name> [--attrs '<json>'] [--advanced]
//                                               [--pattern <pattern-name>] [--select <block-name>]
//   node screenshot.mjs <slug> <name> crop      --block|--pattern … [--as-pattern] [--select <block-name>]
//                                               --steps '<json>' --clip '<json>' [--pad 16]
//   node screenshot.mjs <slug> <name> parent
//   node screenshot.mjs <slug> <name> new-page
//   node screenshot.mjs <slug> <name> templates [--outline '<template title>,<template title>']
//   node screenshot.mjs <slug> <name> template  --template <slug> --block <block-name>
//   node screenshot.mjs <slug> <name> page      --path <site path> [--width 1440] [--height 640]
//   node screenshot.mjs <slug> <name> panel     --path <site path> [--width 1440] [--height 640] [--keep-closed]
//                                               --steps '<json>' --clip '<json>' [--pad 16]
//   node screenshot.mjs <slug> <name> document  [--type post] [--open '<panel title>,<panel title>']
//   node screenshot.mjs <slug> <name> navigation --menu <wp_navigation slug> [--edit --steps '<json>' --clip '<json>']
//   node screenshot.mjs <slug> <name> admin     --start new:<post type>|edit:<post slug>|url:<path> [--keep-modal]
//                                               [--width 1440] [--height 900] --steps '<json>' --clip '<json>' [--pad 16]
//
// Editor shots use a scratch draft, created and deleted here, signed in as the test administrator. `page` shots
// are logged out, clipped to the top of the page. Nothing is saved to the site.
//
// A `crop` shot runs --steps, a JSON list of {"click": "<css in the canvas>"}, {"clickPage": "<css outside the
// canvas>"}, {"outline": "<css outside the canvas>"}, which draws the red outline, {"hide": "<css outside the
// canvas>"}, {"button": "<button label>"} (with an optional "within": "<css>" to search one area),
// {"option": "<list option label>"}, {"type": "<text>"}, {"press": "<key>"}, {"frame": true}, which fits the
// window to the selected block again, or {"preference": ["<scope>", "<key>", <value>]}, each with an optional
// "wait" in ms. {"hover": "<css outside the canvas>"} holds the mouse over it, such as an admin bar item to show its
// dropdown. {"clickText": "<exact text>"} clicks text with no stable class, such as a List View row, and
// "press" takes any Playwright chord, such as "Meta+Alt+O". It then keeps only the box around every --clip target,
// a JSON list of {"canvas": "<css>"} or {"page": "<css>"}, plus --pad pixels.
// `navigation --edit` runs the same steps and clip in the Site Editor with the menu's canvas live. A step that
// would save (保存, Save, 更新, Update) is refused, since a menu is live for the whole site.
// `panel` runs them on a UAT page with the Testing checklist panel open, signed in as the test user, and adds
// {"open": <step number>} (0 closes every step) and {"result": [<step number>, "pass" | "fail"]}, which shows a
// result on a step without saving it. Every form submission there is stopped, so nothing is ever saved.
// `admin` runs them on any admin screen: a new post, an existing one, the admin menu or the Site Editor. It adds
// {"edit": {<post fields>}}, which changes the open post unsaved, such as {"title": "…"} or {"featured_media":
// "{media:<slug>}"}, and {"insert": "<block markup>"}, which adds blocks at the end of it; both take
// {media:<slug>} and {media-url:<slug>}. On an existing post, never click 公開, 更新 or their English labels:
// the post is live.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { parseArgs } from 'node:util';

import {
	ADMIN,
	BASE,
	CACHE,
	TESTER,
	dismissWelcomeGuide,
	editorPostId,
	hideAdminBar,
	hideEnvironmentChrome,
	login,
	wp,
} from './lib.mjs';

const OUTLINE = 'outline: 3px solid #d63638 !important; outline-offset: 2px;';
// The scratch page's title and starting content, which every editor shot shows. UAT_SCRATCH_CONTENT is block
// markup, such as a theme's own title band, inserted before the block being pictured. It is never empty: an
// empty page opens "Choose a pattern" over the editor on a theme with starter patterns.
const SCRATCH_TITLE = process.env.UAT_SCRATCH_TITLE || 'UAT scratch page';
const SCRATCH_CONTENT =
	process.env.UAT_SCRATCH_CONTENT ||
	'<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
// Room above a block for its floating toolbar, and the tallest window a block shot grows to.
const TOOLBAR_ROOM = 96;
const MAX_HEIGHT = 2400;

const {
	positionals: [slug, name, kind],
	values,
} = parseArgs({
	allowPositionals: true,
	options: {
		search: { type: 'string' },
		outline: { type: 'string' },
		block: { type: 'string' },
		attrs: { type: 'string', default: '{}' },
		pattern: { type: 'string' },
		select: { type: 'string' },
		advanced: { type: 'boolean', default: false },
		template: { type: 'string' },
		path: { type: 'string' },
		width: { type: 'string', default: '1440' },
		// Each kind has its own default height.
		height: { type: 'string' },
		start: { type: 'string' },
		'keep-modal': { type: 'boolean', default: false },
		type: { type: 'string', default: 'post' },
		open: { type: 'string', default: '' },
		menu: { type: 'string' },
		'keep-closed': { type: 'boolean', default: false },
		edit: { type: 'boolean', default: false },
		'as-pattern': { type: 'boolean', default: false },
		steps: { type: 'string', default: '[]' },
		clip: { type: 'string', default: '[]' },
		pad: { type: 'string', default: '16' },
	},
});

if (!slug || !name || !kind) {
	console.error('Usage: screenshot.mjs <slug> <name> <kind> [options]');
	process.exit(1);
}

// The cache is per site, so a second site in another language keeps its own shots.
const dir = join(CACHE, 'shots', slug);
const file = join(dir, `${name}.png`);
mkdirSync(dir, { recursive: true });

const browser = await chromium.launch();
const context = await browser.newContext({
	viewport: { width: 1440, height: 900 },
	ignoreHTTPSErrors: true,
});
const page = await context.newPage();
let scratchId = '';

async function openScratchEditor() {
	scratchId = wp([
		`--user=${ADMIN}`,
		'post',
		'create',
		'--post_type=page',
		'--post_status=draft',
		`--post_title=${SCRATCH_TITLE}`,
		`--post_content=${SCRATCH_CONTENT}`,
		'--porcelain',
	])
		.split('\n')
		.pop();
	await login(page, ADMIN);
	await page.goto(`${BASE}/wp-admin/post.php?post=${scratchId}&action=edit`);
	await editorPostId(page);
	await dismissWelcomeGuide(page);
}

/**
 * Returns a registered pattern's block markup, read through WP-CLI.
 *
 * @param {string} patternName Pattern name, for example my-theme/cards-section.
 * @return {string} Markup.
 */
function patternContent(patternName) {
	return wp(
		[
			'eval',
			'echo WP_Block_Patterns_Registry::get_instance()->get_registered( trim( stream_get_contents( STDIN ) ) )["content"] ?? "";',
		],
		patternName
	);
}

/**
 * Replaces each {media:<slug>} with the attachment's ID and each {media-url:<slug>} with its URL, as the builder
 * does in a spec, since IDs differ between environments.
 *
 * @param {string} text Text holding the tokens.
 * @return {string} Text with each token replaced.
 */
function resolveMedia(text) {
	return text.replace(
		/\{media(-url)?:([^}]+)\}/g,
		(token, url, mediaSlug) => {
			const id = wp([
				'post',
				'list',
				'--post_type=attachment',
				`--name=${mediaSlug}`,
				'--field=ID',
			]);
			if (!id) {
				throw new Error(`No media with the slug ${mediaSlug}`);
			}
			return url
				? wp(['eval', `echo wp_get_attachment_url( ${Number(id)} );`])
				: id;
		}
	);
}

/**
 * Waits for the network to go quiet and every image on the page to finish loading. Uploads on a dev-env are
 * redirected to preprod, so a large photo arrives well after the editor has drawn.
 */
async function settle() {
	await page.waitForLoadState('networkidle').catch(() => {});

	// Lazy images off screen, such as the admin bar's avatar, never load, so they are not waited for.
	for (const frame of page.frames()) {
		await frame
			.waitForFunction(
				() =>
					[...document.images]
						.filter((image) => image.loading !== 'lazy')
						.every((image) => image.complete),
				null,
				{ timeout: 30000 }
			)
			.catch(() => {});
	}
}

/**
 * Saves the screenshot with the local environment's banners (UAT_HIDE) hidden, since they sit over the canvas.
 *
 * @param {Object} [clip] Area of the window to keep, as x, y, width and height. The whole window when omitted.
 */
async function capture(clip) {
	await hideEnvironmentChrome(page);
	await page.screenshot({ path: file, clip });
}

/**
 * Inserts the --pattern markup, or one --block built from --attrs, after whatever the scratch page holds.
 */
async function insertRequested() {
	const markup = values.pattern ? patternContent(values.pattern) : '';
	if (!values.block && !markup) {
		return;
	}
	await page.evaluate(
		([blockName, attrs, content, patternName]) => {
			const blocks = content
				? window.wp.blocks.parse(content)
				: [window.wp.blocks.createBlock(blockName, JSON.parse(attrs))];
			// The inserter records the pattern's name, which is what locks it until Edit pattern is clicked.
			if (patternName) {
				blocks[0].attributes.metadata = {
					...blocks[0].attributes.metadata,
					patternName,
				};
			}
			window.wp.data
				.dispatch('core/block-editor')
				.insertBlocks(
					blocks,
					window.wp.data.select('core/block-editor').getBlockCount()
				);
		},
		[
			values.block,
			values.attrs,
			markup,
			values['as-pattern'] ? values.pattern : '',
		]
	);
}

/**
 * Grows the window to fit the outermost block around the selection, and scrolls that block to the top of the
 * canvas with room for the toolbar above it. With nothing selected, such as an inserter shot on an empty
 * scratch page, the window keeps its size.
 */
async function showSelectedBlock() {
	const rootId = await page.evaluate(() => {
		const editor = window.wp.data.select('core/block-editor');
		const selected = editor.getSelectedBlockClientId();
		return selected ? editor.getBlockHierarchyRootClientId(selected) : null;
	});
	if (!rootId) {
		return;
	}
	const root = page
		.frameLocator('iframe[name="editor-canvas"]')
		.locator(`[data-block="${rootId}"]`);
	// The meta box bar under the canvas fixes the iframe's height, so the iframe scrolls inside itself.
	const canvas = page.locator('iframe[name="editor-canvas"]');
	const { height: canvasHeight } = await canvas.boundingBox();
	const { height: blockHeight } = await root.boundingBox();
	const chrome = page.viewportSize().height - canvasHeight;
	const height = Math.min(
		MAX_HEIGHT,
		Math.max(900, Math.ceil(blockHeight + chrome + TOOLBAR_ROOM))
	);

	await page.setViewportSize({ width: 1440, height });
	await page.waitForTimeout(400);
	const canvasBox = await canvas.boundingBox();
	const blockBox = await root.boundingBox();
	await root.evaluate(
		(element, offset) => {
			element.ownerDocument.scrollingElement.scrollTop += offset;
		},
		blockBox.y - canvasBox.y - TOOLBAR_ROOM
	);

	// The last block on a page cannot scroll up to the top, so the window grows by whatever is still cut off.
	for (let attempt = 0; attempt < 3; attempt++) {
		const shownCanvas = await canvas.boundingBox();
		const shownBlock = await root.boundingBox();
		const overflow = Math.ceil(
			shownBlock.y +
				shownBlock.height +
				24 -
				(shownCanvas.y + shownCanvas.height)
		);
		const current = page.viewportSize().height;
		if (overflow <= 0 || current >= MAX_HEIGHT) {
			break;
		}
		await page.setViewportSize({
			width: 1440,
			height: Math.min(MAX_HEIGHT, current + overflow),
		});
		await page.waitForTimeout(400);
	}
}

async function openSidebar(area) {
	await page.evaluate(
		(id) =>
			window.wp.data
				.dispatch('core/interface')
				.enableComplementaryArea('core', id),
		area
	);
	await page.waitForTimeout(400);
}

async function selectFirst(blockName) {
	await page.evaluate((nameToSelect) => {
		const [clientId] = window.wp.data
			.select('core/block-editor')
			.getBlocksByName(nameToSelect);
		window.wp.data.dispatch('core/block-editor').selectBlock(clientId);
	}, blockName);
}

// A step that would save is refused: a navigation menu is live for the whole site, and a scratch page is
// deleted unsaved.
const SAVE_LABEL = /^(保存|Save|更新|Update)$/;

/**
 * Runs crop steps against the editor open in `page`. See the file comment for each step.
 *
 * @param {Array}   steps       Steps, in order.
 * @param {Object}  options     Options.
 * @param {boolean} options.fit Whether a `frame` step refits the window to the selected block.
 */
async function runSteps(steps, { fit }) {
	const canvas = page.frameLocator('iframe[name="editor-canvas"]');
	for (const step of steps) {
		if (
			SAVE_LABEL.test(step.button ?? '') ||
			SAVE_LABEL.test(step.clickText ?? '')
		) {
			throw new Error(
				`Refusing a step that saves: ${JSON.stringify(step)}`
			);
		}
		// Shows a step in the panel as if it had a result, with its badge, without saving anything.
		if (step.result) {
			await page.evaluate(([number, result]) => {
				const item = document.querySelectorAll(
					'.uat-panel .uat-checklist__step'
				)[number - 1];
				const choice = item.querySelector(
					`.uat-checklist__choice--${result}`
				);
				const badge = item.querySelector('.uat-checklist__badge');
				item.dataset.result = result;
				badge.textContent = choice.textContent.trim();
				badge.hidden = false;
			}, step.result);
		}
		// Opens the panel's step with that number and closes the rest; 0 closes them all.
		if (step.open !== undefined) {
			await page.evaluate((number) => {
				document
					.querySelectorAll('.uat-panel .uat-checklist__details')
					.forEach((details, index) => {
						details.open = index === number - 1;
					});
			}, step.open);
		}
		// The editor remembers some UI per user, such as whether a link's Advanced drawer was left open.
		if (step.preference) {
			await page.evaluate(
				([scope, key, value]) =>
					window.wp.data
						.dispatch('core/preferences')
						.set(scope, key, value),
				step.preference
			);
		}
		// Changes the open post in the editor, unsaved: its title, featured image or meta. A whole-value
		// {media:<slug>} becomes the attachment's ID as a number.
		if (step.edit) {
			const edits = JSON.parse(
				resolveMedia(
					JSON.stringify(step.edit).replace(
						/"(\{media:[^}]+\})"/g,
						'$1'
					)
				)
			);
			await page.evaluate(
				(changes) =>
					window.wp.data.dispatch('core/editor').editPost(changes),
				edits
			);
		}
		// Adds blocks from markup at the end of the open post, unsaved.
		if (step.insert) {
			await page.evaluate(
				(markup) =>
					window.wp.data
						.dispatch('core/block-editor')
						.insertBlocks(window.wp.blocks.parse(markup)),
				resolveMedia(step.insert)
			);
		}
		if (step.click) {
			await canvas.locator(step.click).first().click();
		}
		// Holds the mouse over an element outside the canvas, such as an admin bar item, to show its dropdown.
		if (step.hover) {
			await page.locator(step.hover).first().hover();
		}
		if (step.clickPage) {
			await page.locator(step.clickPage).first().click();
		}
		// Text with no stable class, such as a row in List View. "within" limits the search to one area.
		if (step.clickText) {
			const scope = step.within
				? page.locator(step.within).first()
				: page;
			await scope
				.getByText(step.clickText, { exact: true })
				.first()
				.click();
		}
		if (step.outline) {
			await page.addStyleTag({
				content: `${step.outline} { ${OUTLINE} }`,
			});
		}
		// A tooltip left over from a click covers the control the shot is about.
		// The canvas is an iframe, and a tooltip on a control inside it renders there.
		if (step.hide) {
			const css = `${step.hide} { display: none !important; }`;
			await page.addStyleTag({ content: css });
			await page
				.frame({ name: 'editor-canvas' })
				?.addStyleTag({ content: css });
		}
		if (step.button) {
			// "within" limits the search to one area, since the sidebar can hold a button of the same name.
			const scope = step.within
				? page.locator(step.within).first()
				: page;
			await scope
				.getByRole('button', { name: step.button, exact: true })
				.first()
				.click();
		}
		if (step.option) {
			await page
				.getByRole('option', { name: step.option })
				.first()
				.click();
		}
		if (step.type) {
			await page.keyboard.type(step.type);
			// Typing hides the block toolbar until the mouse moves, as it does for a tester.
			await page.mouse.move(8, 400);
			await page.mouse.move(16, 420);
		}
		// Any Playwright key or chord, such as "Escape" or "Meta+Alt+O".
		if (step.press) {
			await page.keyboard.press(step.press);
		}
		// A block that grew during the steps, such as a section given its inner block, is fitted again.
		if (step.frame && fit) {
			await settle();
			await showSelectedBlock();
		}
		await page.waitForTimeout(step.wait ?? 600);
	}
	await settle();
}

/**
 * Saves the box around every clip target, plus `pad` pixels, kept inside the window.
 *
 * @param {Array}  clip Targets, each {"canvas": "<css>"} or {"page": "<css>"}.
 * @param {number} pad  Pixels around the targets.
 */
async function captureClip(clip, pad) {
	const canvas = page.frameLocator('iframe[name="editor-canvas"]');
	const boxes = [];
	for (const target of clip) {
		const locator = target.canvas
			? canvas.locator(target.canvas)
			: page.locator(target.page);
		const box = await locator.first().boundingBox();
		if (!box) {
			throw new Error(
				`Nothing on screen to clip to: ${JSON.stringify(target)}`
			);
		}
		boxes.push(box);
	}
	const { width, height } = page.viewportSize();
	const left = Math.max(0, Math.min(...boxes.map((box) => box.x)) - pad);
	const top = Math.max(0, Math.min(...boxes.map((box) => box.y)) - pad);
	const right = Math.min(
		width,
		Math.max(...boxes.map((box) => box.x + box.width)) + pad
	);
	const bottom = Math.min(
		height,
		Math.max(...boxes.map((box) => box.y + box.height)) + pad
	);
	await capture({
		x: left,
		y: top,
		width: right - left,
		height: bottom - top,
	});
}

/**
 * Runs --steps outside a block shot, then saves the --clip area, or the whole window when --clip is empty.
 */
async function runStepsThenCapture() {
	await runSteps(JSON.parse(values.steps), { fit: false });
	const clip = JSON.parse(values.clip);
	if (clip.length) {
		await captureClip(clip, Number(values.pad));
	} else {
		await capture();
	}
}

const shots = {
	async inserter() {
		await openScratchEditor();
		await page.click('.editor-document-tools__inserter-toggle');
		await page.fill('.block-editor-inserter__search input', values.search);
		await page
			.locator('.block-editor-block-types-list__item')
			.first()
			.waitFor();

		if (values.outline) {
			const tile = `.editor-block-list-item-${values.outline.replace('/', '-')}`;
			await page.addStyleTag({ content: `${tile} { ${OUTLINE} }` });
		}

		await page.waitForTimeout(500);
		await settle();
		await capture();
	},

	async block() {
		await openScratchEditor();

		// With --select alone, a block UAT_SCRATCH_CONTENT put on the scratch page is shown.
		await insertRequested();
		await selectFirst(values.select ?? values.block);
		await openSidebar('edit-post/block');

		if (values.advanced) {
			const advanced = page.locator(
				'.block-editor-block-inspector__advanced button'
			);
			if ((await advanced.getAttribute('aria-expanded')) === 'false') {
				await advanced.click();
			}
		}

		await settle();
		await showSelectedBlock();
		await page.waitForTimeout(400);
		await settle();
		await capture();
	},

	// A small area of the editor after a few actions, for a step whose control is small or hard to find.
	async crop() {
		const clip = JSON.parse(values.clip);
		if (!clip.length) {
			throw new Error(
				'The crop kind needs --clip, a JSON list of the targets to keep, and it is empty.'
			);
		}

		await openScratchEditor();
		await insertRequested();
		await selectFirst(values.select ?? values.block);
		await openSidebar('edit-post/block');
		await settle();
		await showSelectedBlock();

		await runSteps(JSON.parse(values.steps), { fit: true });
		await captureClip(clip, Number(values.pad));
	},

	// The "Choose a pattern" window a new, empty page opens with. The auto-draft WordPress creates for it is
	// deleted with the scratch page.
	async 'new-page'() {
		await login(page, ADMIN);
		await page.goto(`${BASE}/wp-admin/post-new.php?post_type=page`);
		scratchId = await editorPostId(page);
		await dismissWelcomeGuide(page);
		await page.locator('.editor-start-page-options__modal').waitFor();
		await page.waitForTimeout(1500);
		await settle();
		await capture();
	},

	async parent() {
		await openScratchEditor();
		await openSidebar('edit-post/document');
		await page.locator('.editor-post-parent__panel-toggle').first().click();
		await page.waitForTimeout(500);
		await settle();
		await capture();
	},

	async templates() {
		await login(page, ADMIN);
		await page.setViewportSize({ width: 1440, height: 1300 });
		await page.goto(`${BASE}/wp-admin/site-editor.php?p=%2Ftemplate`);
		await page.locator('.dataviews-view-grid').first().waitFor();
		await page.waitForTimeout(2500);

		for (const title of (values.outline ?? '').split(',').filter(Boolean)) {
			await page
				.locator('.dataviews-view-grid__card')
				.filter({ hasText: title.trim() })
				.first()
				.evaluate((card, style) => {
					card.style.cssText += style;
				}, OUTLINE);
		}

		await settle();
		await capture();
	},

	async template() {
		await login(page, ADMIN);
		const id = encodeURIComponent(
			`/wp_template/${wp(['eval', 'echo get_stylesheet();'])}//${values.template}`
		);
		await page.goto(`${BASE}/wp-admin/site-editor.php?p=${id}&canvas=edit`);
		await page.waitForFunction(
			(blockName) =>
				window.wp?.data
					?.select('core/block-editor')
					?.getBlocksByName(blockName).length,
			values.block
		);
		await page.evaluate(() =>
			window.wp.data
				.dispatch('core/preferences')
				.set('core/edit-site', 'welcomeGuide', false)
		);
		await page.keyboard.press('Escape');
		await selectFirst(values.block);
		await page.evaluate(() =>
			window.wp.data.dispatch('core/editor').setIsListViewOpened(true)
		);
		await openSidebar('edit-post/block');
		await page
			.frameLocator('iframe[name="editor-canvas"]')
			.locator(`[data-type="${values.block}"]`)
			.first()
			.evaluate((el) => el.scrollIntoView({ block: 'center' }));
		await page.waitForTimeout(1500);
		await settle();
		await capture();
	},

	// A new post's sidebar, with the named panels opened. The auto-draft is deleted with the scratch page.
	async document() {
		// Tall enough for every panel in a new post's sidebar.
		await page.setViewportSize({ width: 1440, height: 1300 });
		await login(page, ADMIN);
		await page.goto(
			`${BASE}/wp-admin/post-new.php?post_type=${values.type}`
		);
		scratchId = await editorPostId(page);
		await dismissWelcomeGuide(page);
		await page.keyboard.press('Escape');
		await openSidebar('edit-post/document');

		for (const title of values.open.split(',').filter(Boolean)) {
			const toggle = page
				.locator('.editor-sidebar .components-panel__body-toggle')
				.filter({ hasText: title.trim() })
				.first();
			if ((await toggle.getAttribute('aria-expanded')) === 'false') {
				await toggle.click();
			}
		}

		await page.waitForTimeout(800);
		await settle();
		await capture();
	},

	async navigation() {
		// Tall enough to show the add button under a long menu.
		await page.setViewportSize({ width: 1440, height: 1100 });
		await login(page, ADMIN);
		const id = wp([
			'post',
			'list',
			'--post_type=wp_navigation',
			`--name=${values.menu}`,
			'--field=ID',
		]);
		// --edit opens the menu with its canvas live, as a tester sees it after clicking the menu, so steps can
		// select and change items. Nothing is saved: a step that would save is refused.
		const canvasParam = values.edit ? '&canvas=edit' : '';
		await page.goto(
			`${BASE}/wp-admin/site-editor.php?p=${encodeURIComponent(`/wp_navigation/${id}`)}${canvasParam}`
		);
		if (values.edit) {
			await page.locator('iframe[name="editor-canvas"]').waitFor();
			await page.waitForTimeout(2500);
			await runStepsThenCapture();
			return;
		}
		await page.locator('.list-view-appender button').first().waitFor();
		await page.waitForTimeout(1500);
		await settle();
		await capture();
	},

	// Any admin screen after --steps, signed in as the test administrator. --start new:<post type> opens a new
	// post, whose auto-draft is deleted afterwards; edit:<post slug> opens an existing post; url:<path> opens any
	// path, such as /wp-admin/edit.php or a Site Editor route. Nothing a step changes in the editor is saved.
	async admin() {
		await page.setViewportSize({
			width: Number(values.width),
			height: Number(values.height ?? '900'),
		});
		await login(page, ADMIN);
		const [where, target] = (values.start ?? '').split(/:(.*)/s);
		if (where === 'new') {
			await page.goto(
				`${BASE}/wp-admin/post-new.php?post_type=${target}`
			);
		} else if (where === 'edit') {
			const id = wp([
				'post',
				'list',
				'--post_type=any',
				`--name=${target}`,
				'--field=ID',
			]);
			if (!id) {
				throw new Error(`No post with the slug ${target}`);
			}
			await page.goto(`${BASE}/wp-admin/post.php?post=${id}&action=edit`);
		} else if (where === 'url') {
			await page.goto(`${BASE}${target}`);
		} else {
			throw new Error(
				'--start takes new:<post type>, edit:<post slug> or url:<path>'
			);
		}

		if (where !== 'url') {
			const id = await editorPostId(page);
			if (where === 'new') {
				scratchId = id;
			}
			// The sidebar open or closed is a per-user preference that other runs change.
			await openSidebar('edit-post/document');
			// --keep-modal leaves a new page's pattern chooser open, for a shot of it.
			if (!values['keep-modal']) {
				await page.keyboard.press('Escape');
			}
		}
		// Either editor's welcome guide, when the screen is one.
		await page.evaluate(() => {
			const preferences = window.wp?.data?.dispatch('core/preferences');
			preferences?.set('core/edit-post', 'welcomeGuide', false);
			preferences?.set('core/edit-site', 'welcomeGuide', false);
		});
		await page.waitForTimeout(1500);
		await settle();
		await runStepsThenCapture();
	},

	// The Testing checklist panel on a UAT page, signed in as the test user, after --steps. --keep-closed leaves
	// the panel shut, for a shot of the admin bar item or of the pop-out window
	// (--path with ?uat-view=window&uat-checklist=<id>).
	async panel() {
		await page.setViewportSize({
			width: Number(values.width),
			height: Number(values.height ?? '640'),
		});
		await login(page, TESTER);
		// No result or comment is ever saved: every form submission is stopped before the panel's handler sees it.
		// Added after signing in, since the login form is a form too.
		await page.addInitScript(() => {
			document.addEventListener(
				'submit',
				(event) => {
					event.preventDefault();
					event.stopImmediatePropagation();
				},
				true
			);
		});
		// A fresh browser is a tester's first visit, when the panel opens by itself. --keep-closed marks it seen
		// first, so the page loads with the panel shut.
		if (values['keep-closed']) {
			await page.addInitScript(() =>
				window.localStorage.setItem('uat-checklist:seen', '1')
			);
		}
		await page.goto(`${BASE}${values.path}`);
		if (!values['keep-closed']) {
			const panel = page.locator('.uat-panel');
			if (!(await panel.isVisible())) {
				await page
					.locator(
						'#wp-admin-bar-uat-checklist > .ab-item:visible, .uat-panel-toggle:visible'
					)
					.first()
					.click();
			}
			await page
				.locator('.uat-panel .uat-checklist__step')
				.first()
				.waitFor();
		}
		await page.waitForTimeout(1000);
		await runStepsThenCapture();
	},

	async page() {
		const width = Number(values.width);
		const height = Number(values.height ?? '640');
		await page.setViewportSize({ width, height: Math.max(height, 844) });
		await page.goto(`${BASE}${values.path}`);
		await page.waitForLoadState('networkidle');
		await hideAdminBar(page);
		await page.screenshot({
			path: file,
			clip: { x: 0, y: 0, width, height },
		});
	},
};

try {
	if (!shots[kind]) {
		throw new Error(
			`Unknown kind "${kind}". Use one of: ${Object.keys(shots).join(', ')}`
		);
	}

	await shots[kind]();
	console.error(`Saved ${file}`);
} finally {
	await browser.close();

	if (scratchId) {
		wp(['post', 'delete', scratchId, '--force']);
	}
}
