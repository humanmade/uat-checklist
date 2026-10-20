// Checks a built checklist, and its UAT page when it has one, against its spec, read-only, signed in as the test
// tester. Saves no result.
//
//   node verify-page.mjs docs/uat/<slug>.json [--checklist-only]
//
// Prints one PASS or FAIL line per check and exits non-zero if any failed.
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

import { BASE, TESTER, login, wp } from './lib.mjs';

const specPath = process.argv[2];
const spec = JSON.parse(readFileSync(specPath, 'utf8'));

// A spec checked against a site in another language passes or fails for the wrong reasons.
const locale = wp(['eval', 'echo get_locale();']);
const language = spec.language ?? 'en';
// Normalised as build-page.py does: a locale's separator is an underscore, and the spec's language is not changed.
if (
	!locale
		.toLowerCase()
		.replaceAll('-', '_')
		.startsWith(language.toLowerCase())
) {
	console.error(
		`${specPath} is written in "${language}", but ${BASE} is in ${locale}. Set UAT_URL to the site the spec is for.`
	);
	process.exit(1);
}
if (spec.hub || spec.reference) {
	console.error(
		`The ${spec.hub ? 'hub' : 'reference page'} has no checklist to verify. Check that every link on it returns 200 instead.`
	);
	process.exit(1);
}

const expectedSteps = spec.checklist.reduce(
	(sum, section) => sum + section.steps.length,
	0
);
const checklistOnly = process.argv.includes('--checklist-only');
const findPost = (type, field) =>
	wp([
		'post',
		'list',
		`--post_type=${type}`,
		'--post_status=publish',
		`--name=${spec.slug}`,
		`--field=${field}`,
	]).split('\n')[0];

const checklistId = Number(findPost('uat_checklist', 'ID'));
// Under the hub page or at the top level, wherever build-page.py put it.
const url = checklistOnly ? '' : findPost('page', 'url');
if (!checklistId || (!checklistOnly && !url)) {
	console.error(
		`No published ${checklistId ? 'page' : 'checklist'} with the slug ${spec.slug}. Run build-page.py first, or pass --checklist-only for a checklist with no page.`
	);
	process.exit(1);
}
const rows = [];
// Playwright's messages carry terminal colour codes.
const plain = (text) => text.replace(/\x1b\[[0-9;]*m/g, '');
const check = (label, pass, detail = '') =>
	rows.push(
		`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `: ${detail}` : ''}`
	);

const browser = await chromium.launch();
const context = await browser.newContext({
	viewport: { width: 1440, height: 900 },
	ignoreHTTPSErrors: true,
});
const page = await context.newPage();
const errors = [];
let saves = 0;
page.on('pageerror', (error) => errors.push(error.message));
page.on('console', (message) => {
	if (message.type() === 'error') {
		errors.push(message.text());
	}
});
context.on('request', (request) => {
	if (request.method() === 'POST' && request.url().includes('/results')) {
		saves++;
	}
});

try {
	await login(page, TESTER);
	// A fresh browser is a tester's first visit, when the panel opens by itself. Marked seen, so the panel starts
	// shut and the check below opens it from the admin bar as a returning tester does.
	await page.addInitScript(() =>
		window.localStorage.setItem('uat-checklist:seen', '1')
	);

	// A checklist with no page is checked from the home page, where nothing names it, so it is picked by hand.
	const response = await page.goto(url || `${BASE}/`);
	await page.waitForLoadState('networkidle');

	if (url) {
		check(
			'page loads',
			response.status() === 200,
			`${response.status()} ${url}`
		);

		const robots = await page
			.locator('meta[name="robots"]')
			.getAttribute('content');
		// Yoast's per-page noindex, or core's on every page once Settings → Reading discourages search engines.
		check(
			'hidden from search engines',
			/noindex/.test(robots ?? ''),
			`${robots ?? 'no robots meta'}; turn on Settings → Reading → "Discourage search engines", or activate Yoast`
		);

		const menuLinks = await page
			.locator(`nav a[href$="${new URL(url).pathname}"]`)
			.count();
		check(
			'not linked from any menu',
			menuLinks === 0,
			`${menuLinks} menu links`
		);
	}

	await page.locator('#wp-admin-bar-uat-checklist > .ab-item').click();
	// The "Loading…" placeholder has no value, so this waits for the list itself.
	await page.waitForFunction(
		() => document.querySelector('#uat-panel-select').value !== ''
	);

	if (url) {
		const opens =
			(await page.locator('#uat-panel-select').inputValue()) ===
			String(checklistId);
		check(
			'the page opens its own checklist',
			opens,
			opens ? '' : "set the checklist's Instructions page to this page"
		);
	} else {
		await page
			.locator('#uat-panel-select')
			.selectOption(String(checklistId));
	}

	await page.waitForFunction(
		(id) =>
			document.querySelector(
				`#uat-panel .uat-checklist[data-checklist-id="${id}"] .uat-checklist__step`
			),
		checklistId
	);
	// The pop-out link, built by the plugin, names this checklist.
	const windowLink = await page
		.locator('#uat-panel .uat-checklist__window')
		.getAttribute('href');
	check(
		'the pop-out link names the checklist',
		new URL(windowLink).searchParams.get('uat-checklist') ===
			String(checklistId),
		windowLink
	);
	const panel = await page.evaluate(() => ({
		title: document.querySelector('#uat-panel .uat-checklist__title')
			.textContent,
		steps: document.querySelectorAll('#uat-panel .uat-checklist__step')
			.length,
		picker: document.querySelector('#uat-panel-select').selectedOptions[0]
			.textContent,
	}));
	check('panel names the block', panel.title === spec.title, panel.title);
	check(
		'panel holds every step',
		panel.steps === expectedSteps,
		`${panel.steps} of ${expectedSteps}`
	);
	check(
		'picker lists the checklist',
		panel.picker.startsWith(spec.title),
		panel.picker
	);
	if (url) {
		const instructions = await page
			.locator('#uat-panel .uat-checklist__page')
			.getAttribute('href')
			.catch(() => null);
		check(
			'the instructions link goes to the page',
			instructions === url,
			instructions ?? 'no link'
		);
	}

	// Clicked the way a tester would, so anything covering the button fails here rather than being skipped.
	try {
		await page
			.locator('#uat-panel .uat-panel__close')
			.click({ timeout: 5000 });
		check('panel closes from its Close button', true);
	} catch (error) {
		const lines = plain(error.message).split('\n');
		check(
			'panel closes from its Close button',
			false,
			lines.find((line) => line.includes('intercepts'))?.trim() ??
				lines[0]
		);
		await page.keyboard.press('Escape');
	}

	// The screenshots inside the checklist's steps, which the panel shows.
	const brokenStepImages = await page.evaluate(() =>
		[...document.querySelectorAll('#uat-panel .uat-checklist__figure img')]
			.filter((img) => img.complete && !img.naturalWidth)
			.map((img) => img.src)
	);
	check(
		'every step screenshot loads',
		brokenStepImages.length === 0,
		brokenStepImages.join(', ')
	);

	if (url) {
		// Lazy images only load once scrolled to.
		for (let y = 0; y < 20000; y += 700) {
			await page.evaluate((top) => window.scrollTo(0, top), y);
			await page.waitForTimeout(100);
		}
		const brokenImages = await page.evaluate(() =>
			[...document.querySelectorAll('.wp-block-image img')]
				.filter((img) => !img.naturalWidth)
				.map((img) => img.src)
		);
		check(
			'every screenshot loads',
			brokenImages.length === 0,
			brokenImages.join(', ')
		);

		const links = await page.evaluate(
			(base) => [
				...new Set(
					[...document.querySelectorAll('main a[href]')]
						.map((a) => a.href.split('#')[0])
						.filter(
							(href) =>
								href.startsWith(base) &&
								!href.includes('/wp-content/')
						)
				),
			],
			BASE
		);
		const deadLinks = [];
		for (const link of links) {
			const status = (await page.request.get(link)).status();
			if (status >= 400) {
				deadLinks.push(`${status} ${link.replace(BASE, '')}`);
			}
		}
		check(
			'every link in the page content resolves',
			deadLinks.length === 0,
			deadLinks.join(', ') || `${links.length} links`
		);
	}

	const pageId = url
		? await page.evaluate(() =>
				Number(
					document.body.className.match(/\bpage-id-(\d+)\b/)?.[1] ?? 0
				)
			)
		: 0;
	const editorChecks = [
		['every block in the checklist is valid in the editor', checklistId],
		...(pageId
			? [['every block on the page is valid in the editor', pageId]]
			: []),
	];

	for (const [label, postId] of editorChecks) {
		await page.goto(`${BASE}/wp-admin/post.php?post=${postId}&action=edit`);
		await page.waitForFunction(
			() =>
				window.wp?.data?.select('core/block-editor')?.getBlocks().length
		);
		const invalid = await page.evaluate(() => {
			const walk = (blocks) =>
				blocks.flatMap((block) => [block, ...walk(block.innerBlocks)]);
			return walk(window.wp.data.select('core/block-editor').getBlocks())
				.filter((block) => !block.isValid)
				.map((block) => block.name);
		});
		check(label, invalid.length === 0, invalid.join(', '));
	}

	check('no console errors', errors.length === 0, errors.join(' | '));
	check('saved no results', saves === 0, `${saves} saves`);
} catch (error) {
	check('verification ran to the end', false, error.message.split('\n')[0]);
} finally {
	await browser.close();
}

console.error(rows.join('\n'));
process.exitCode = rows.some((row) => row.startsWith('FAIL')) ? 1 : 0;
