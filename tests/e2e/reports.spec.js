/*
 * Issue reports: the admin bar item and the dialog it opens, the panel's footer button, the places that get neither,
 * and the triage screens a manager moves reports through.
 */
import {
	expect,
	openChecklist,
	panel,
	storageState,
	test as base,
	USERS,
} from './utils';

// Every report these tests send starts with this, which is how the cleanup finds them among any others on the site.
const MARKER = 'E2E report';
const ROUTE = '/wp-json/uat-checklist/v1/reports';
const LIST = '/wp-admin/edit.php?post_type=uat_report';
const SENT = 'Thank you. Your report was sent.';

// A 1×1 PNG, enough for WordPress to accept the upload and make an attachment of it.
const PNG = {
	name: 'shot.png',
	mimeType: 'image/png',
	buffer: Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
		'base64'
	),
};

// Named as a PNG, so the browser reports it as one: only reading the content shows it is not.
const FAKE_PNG = {
	name: 'shot.png',
	mimeType: 'image/png',
	buffer: Buffer.from('not an image'),
};

/**
 * Permanently deletes every report whose text holds the marker, as the manager: trashed first, then deleted from
 * the trash, which also deletes each report's screenshot.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 */
async function deleteReports(browser) {
	const manager = await browser.newPage({
		storageState: storageState(USERS.manager),
	});
	const search = `&s=${encodeURIComponent(MARKER)}`;

	try {
		for (const [view, action, done] of [
			['', 'trash', /trashed=/],
			['&post_status=trash', 'delete', /deleted=/],
		]) {
			await manager.goto(LIST + view + search);

			if (
				(await manager.locator('#the-list tr:not(.no-items)').count()) >
				0
			) {
				await manager.locator('#cb-select-all-1').check();
				await manager
					.locator('#bulk-action-selector-top')
					.selectOption(action);
				await Promise.all([
					manager.waitForURL(done),
					manager.locator('#doaction').click(),
				]);
			}
		}
	} finally {
		await manager.close();
	}
}

const test = base.extend({
	// Deletes every report with the marker after each test, whichever way it sent them.
	cleanup: [
		async ({ browser }, use) => {
			await use();
			await deleteReports(browser);
		},
		{ auto: true },
	],

	// Sends reports as the tester through the REST route.
	reports: async ({ playwright, baseURL }, use) => {
		const request = await playwright.request.newContext({
			baseURL,
			storageState: storageState(USERS.tester),
		});
		const nonce = await (
			await request.get('/wp-admin/admin-ajax.php?action=rest-nonce')
		).text();
		const post = (route, options = {}) =>
			request.post(route, {
				...options,
				headers: { 'X-WP-Nonce': nonce },
			});

		await use({
			post,

			/**
			 * @param {string}  description What the report says, after the marker.
			 * @param {Object=} screenshot  A file for the multipart body, or none.
			 * @return {Promise<number>} The report's ID.
			 */
			async send(description, screenshot) {
				const response = await post(ROUTE, {
					multipart: {
						description: `${MARKER}: ${description}`,
						page_url: `${baseURL}/`,
						page_id: '0',
						page_title: 'Home',
						...(screenshot && { screenshot }),
					},
				});
				expect(response.status()).toBe(201);

				return (await response.json()).id;
			},
		});

		await request.dispose();
	},
});

/**
 * @param {import('@playwright/test').Page} page Page.
 * @return {import('@playwright/test').Locator} The Report an issue item in the admin bar.
 */
function toggle(page) {
	return page.locator('#wp-admin-bar-uat-report > .ab-item');
}

/**
 * @param {import('@playwright/test').Page} page Page.
 * @return {import('@playwright/test').Locator} The dialog.
 */
function dialog(page) {
	return page.locator('dialog.uat-report');
}

/**
 * @param {import('@playwright/test').Page} page Page.
 * @return {import('@playwright/test').Locator} The dialog's live region.
 */
function status(page) {
	return dialog(page).getByRole('status');
}

/**
 * Opens the dialog from the admin bar item and waits for it.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function openDialog(page) {
	await toggle(page).click();
	await expect(dialog(page)).toBeVisible();
}

/**
 * Whether the element at the middle of a locator's box is the element itself or inside it, which a covered control
 * is not.
 *
 * @param {import('@playwright/test').Locator} locator Locator.
 * @return {Promise<boolean>} Whether nothing covers it.
 */
function isTopmost(locator) {
	return locator.evaluate((element) => {
		const box = element.getBoundingClientRect();
		const top = document.elementFromPoint(
			box.x + box.width / 2,
			box.y + box.height / 2
		);

		return element.contains(top);
	});
}

/**
 * Collects what the page logs as an error, to assert on once it has settled.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {string[]} The errors, filled as they happen.
 */
function collectErrors(page) {
	const errors = [];

	page.on('console', (message) => {
		if (message.type() === 'error') {
			errors.push(message.text());
		}
	});
	page.on('pageerror', (error) => errors.push(error.message));

	return errors;
}

/**
 * Opens the Reports list as the manager, in a page of their own, narrowed to the marker's reports.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @param {string}                             query   Query string to add, such as `&post_status=pending`.
 * @return {Promise<import('@playwright/test').Page>} The page.
 */
async function openList(browser, query = '') {
	const manager = await browser.newPage({
		storageState: storageState(USERS.manager),
	});
	await manager.goto(`${LIST}&s=${encodeURIComponent(MARKER)}${query}`);

	return manager;
}

/**
 * @param {import('@playwright/test').Page} manager A page on the Reports list.
 * @param {number}                          id      Report ID.
 * @return {import('@playwright/test').Locator} The report's row.
 */
function row(manager, id) {
	return manager.locator(`#post-${id}`);
}

/**
 * @param {import('@playwright/test').Page} manager A page on the Reports list.
 * @param {string}                          view    View's class in `.subsubsub`: `pending`, `uat-done` and so on.
 * @return {Promise<number>} The number the view's link shows, or 0 when it has no link.
 */
async function viewCount(manager, view) {
	const count = manager.locator(`.subsubsub .${view} .count`);

	// Core shows no link for a status nothing is in.
	return (await count.count()) === 0
		? 0
		: Number((await count.textContent()).replace(/\D/g, ''));
}

/**
 * Marks the page, so a later full page load shows as the mark gone.
 *
 * @param {import('@playwright/test').Page} manager Page.
 */
function markPage(manager) {
	return manager.evaluate(() => {
		window.e2eSamePage = true;
	});
}

/**
 * @param {import('@playwright/test').Page} manager Page.
 * @return {Promise<boolean>} Whether the page is the one that was marked.
 */
function isSamePage(manager) {
	return manager.evaluate(() => window.e2eSamePage === true);
}

for (const [where, path] of [
	['the front end', null],
	['wp-admin', '/wp-admin/'],
]) {
	test(`the report item is in the admin bar on ${where}, and its dialog names the tester and the page`, async ({
		page,
		site,
	}) => {
		const target = path ?? (await site.createPage('Report target')).link;

		await page.goto(target);
		await expect(
			page.locator('#wpadminbar').getByRole('button', {
				name: 'Report an issue',
			})
		).toBeVisible();
		const address = page.url();

		await openDialog(page);
		// The item is a `#` link, which would otherwise jump to the top of the page and add a history entry.
		expect(page.url()).toBe(address);
		await expect(dialog(page).getByLabel('What went wrong?')).toBeFocused();
		await expect(dialog(page).locator('.uat-report__detail')).toHaveText([
			new RegExp(`^${await page.title()}`),
			USERS.tester,
		]);
		await expect(dialog(page).locator('.uat-report__page-url')).toHaveText(
			new URL(page.url()).origin + new URL(page.url()).pathname
		);

		await page.keyboard.press('Escape');
		await expect(dialog(page)).toBeHidden();
		await expect(toggle(page)).toBeFocused();
	});
}

test('on a phone the report item stays in the admin bar, named by its hidden label', async ({
	page,
}) => {
	await page.setViewportSize({ width: 375, height: 700 });
	await page.goto('/');

	await expect(
		page.locator('#wpadminbar').getByRole('button', {
			name: 'Report an issue',
		})
	).toBeVisible();
	await openDialog(page);
});

test('the dialog refuses an empty description, and the description stops at its limit', async ({
	page,
}) => {
	const sent = [];
	page.on('request', (request) => {
		if (request.url().includes('uat-checklist/v1/reports')) {
			sent.push(request.url());
		}
	});

	await page.goto('/');
	await openDialog(page);
	const description = dialog(page).getByLabel('What went wrong?');

	// Spaces only: the form trims the text, so this is as empty as nothing.
	await description.fill('   ');
	await dialog(page).getByRole('button', { name: 'Submit report' }).click();

	await expect(status(page)).toHaveText('Describe the problem first.');
	await expect(status(page)).toHaveClass(/\bis-error\b/);
	await expect(description).toHaveAttribute('aria-invalid', 'true');
	await expect(description).toBeFocused();
	expect(sent).toEqual([]);

	await description.fill('Something');
	await expect(description).not.toHaveAttribute('aria-invalid');

	// There is no counter: the limit is the field's maxlength, which typing and pasting both stop at.
	const limit = Number(await description.getAttribute('maxlength'));
	expect(limit).toBeGreaterThan(0);
	await description.fill('');
	await page.keyboard.insertText('x'.repeat(limit + 1));
	await expect(description).toHaveValue('x'.repeat(limit));
});

test('the dialog refuses a file that is not an image, previews one above the upload button and lets it be removed', async ({
	page,
}) => {
	await page.goto('/');
	await openDialog(page);
	const file = dialog(page).locator('.uat-report__file');
	const upload = dialog(page).locator('.uat-report__upload');
	const preview = dialog(page).locator('.uat-report__preview');

	await file.setInputFiles({
		name: 'notes.txt',
		mimeType: 'text/plain',
		buffer: Buffer.from('hello'),
	});
	await expect(status(page)).toHaveText(
		'Choose a JPEG, PNG, GIF or WebP image.'
	);
	await expect(status(page)).toHaveClass(/\bis-error\b/);
	await expect(file).toHaveAttribute('aria-invalid', 'true');
	await expect(preview).toBeHidden();

	await file.setInputFiles(FAKE_PNG);
	await expect(status(page)).toHaveText(
		'Choose a JPEG, PNG, GIF or WebP image.'
	);
	await expect(preview).toBeHidden();
	expect(await file.evaluate((input) => input.files.length)).toBe(0);

	await file.setInputFiles(PNG);
	await expect(preview).toBeVisible();
	await expect(status(page)).toHaveText('');
	await expect(file).not.toHaveAttribute('aria-invalid');
	await expect(upload).toHaveText('Replace screenshot');
	const above = (await preview.boundingBox()).y;
	expect(above).toBeLessThan((await upload.boundingBox()).y);

	await dialog(page)
		.getByRole('button', { name: 'Remove screenshot' })
		.click();
	await expect(preview).toBeHidden();
	await expect(upload).toHaveText('Add a screenshot');
	await expect(file).toBeFocused();
	expect(await file.evaluate((input) => input.files.length)).toBe(0);
});

test('a report with a screenshot is announced as sent and kept as pending, by the tester, against the page address without its query or hash', async ({
	page,
	browser,
	site,
}) => {
	const target = await site.createPage('Report target');
	const { origin, pathname } = new URL(target.link);
	const text = `${MARKER}: the button does nothing`;

	await page.goto(`${target.link}?token=secret#section`);
	const title = await page.title();
	await openDialog(page);
	await dialog(page).getByLabel('What went wrong?').fill(text);
	await dialog(page).getByLabel('Add a screenshot').setInputFiles(PNG);

	const sending = page.waitForResponse(
		(response) =>
			response.url().includes('uat-checklist/v1/reports') &&
			response.request().method() === 'POST'
	);
	await dialog(page).getByRole('button', { name: 'Submit report' }).click();
	const response = await sending;

	expect(response.status()).toBe(201);
	expect(response.request().headers()['content-type']).toMatch(
		/^multipart\/form-data/
	);
	await expect(status(page)).toHaveText(SENT);
	await expect(dialog(page).getByLabel('What went wrong?')).toBeHidden();
	await expect(
		dialog(page).getByRole('button', { name: 'Submit report' })
	).toBeHidden();
	await expect(
		dialog(page).getByRole('button', { name: 'Close' })
	).toBeFocused();

	await dialog(page).getByRole('button', { name: 'Close' }).click();
	await expect(dialog(page)).toBeHidden();
	await expect(toggle(page)).toBeFocused();

	// Opened again, the form is back and empty.
	await openDialog(page);
	await expect(dialog(page).getByLabel('What went wrong?')).toHaveValue('');
	await expect(status(page)).toHaveText('');

	const manager = await openList(browser);
	const sent = manager.locator('#the-list tr', { hasText: text });
	await expect(sent).toHaveCount(1);
	await expect(sent.locator('.column-uat_status')).toHaveText('Pending');
	await expect(sent.locator('.column-uat_reporter')).toHaveText(USERS.tester);
	await expect(sent.locator('.column-uat_page a')).toHaveAttribute(
		'href',
		origin + pathname
	);
	await expect(sent.locator('.column-uat_page a')).toHaveText(title);
	await expect(sent.locator('.column-uat_screenshot img')).toHaveCount(1);
	await manager.close();
});

test("a report from an address the server refuses shows the server's message, and one that fails for any other reason shows the generic one", async ({
	page,
	baseURL,
}) => {
	const text = `${MARKER}: the address is refused`;
	const generic =
		'The report could not be sent. Check your connection and try again.';
	const submit = dialog(page).getByRole('button', { name: 'Submit report' });
	let respond = null;

	await page.route(/uat-checklist\/v1\/reports/, async (route) => {
		if (route.request().method() === 'POST') {
			await respond(route);
			return;
		}

		await route.continue();
	});

	await page.goto('/');
	await openDialog(page);
	await dialog(page).getByLabel('What went wrong?').fill(text);

	// The page address is read from the browser, so the way to send another host is to change it on the way out.
	respond = (route) =>
		route.continue({
			postData: route
				.request()
				.postData()
				.replace(baseURL, 'http://elsewhere.example'),
		});
	await submit.click();
	await expect(status(page)).toHaveText(
		`The page address must be on ${
			new URL(baseURL).host
		}, the address this site is set up to use. Open the site at that address and send the report again.`
	);
	await expect(status(page)).toHaveClass(/\bis-error\b/);
	await expect(submit).toBeEnabled();
	await expect(dialog(page).getByLabel('What went wrong?')).toHaveValue(text);

	respond = (route) =>
		route.fulfill({
			status: 500,
			contentType: 'application/json',
			body: JSON.stringify({
				code: 'internal_server_error',
				message: 'There has been a critical error on this website.',
				data: { status: 500 },
			}),
		});
	await submit.click();
	await expect(status(page)).toHaveText(generic);

	respond = (route) => route.abort();
	await submit.click();
	await expect(status(page)).toHaveText(generic);
	await expect(status(page)).toHaveClass(/\bis-error\b/);
	await expect(dialog(page).getByLabel('What went wrong?')).toHaveValue(text);
});

test('Tab stays inside the dialog and a report can be sent from the keyboard', async ({
	page,
}) => {
	/**
	 * Presses a key and names what has focus: the dialog's control by its class, or `body` when focus has left the
	 * page for the browser's own controls, which is as far out as a modal dialog lets it go.
	 *
	 * @param {string} key Key to press.
	 * @return {Promise<string>} What has focus.
	 */
	const press = async (key) => {
		await page.keyboard.press(key);

		return page.evaluate(() => {
			const { activeElement } = document;

			return activeElement === document.body
				? 'body'
				: activeElement.className;
		});
	};
	const file = dialog(page).locator('.uat-report__file');

	await page.goto('/');
	await toggle(page).focus();
	await page.keyboard.press('Enter');
	await expect(dialog(page)).toBeVisible();
	await expect(dialog(page).getByLabel('What went wrong?')).toBeFocused();

	// The markup's order, with Close first in the header. Nothing behind the dialog, the button included, is reached.
	expect([
		await press('Tab'),
		await press('Tab'),
		await press('Tab'),
	]).toEqual(['uat-report__file', 'uat-report__submit', 'body']);
	expect([await press('Tab'), await press('Tab')]).toEqual([
		'uat-report__close',
		'uat-report__description',
	]);

	// With a screenshot, Remove joins the order between the description and the file field.
	await file.setInputFiles(PNG);
	expect([await press('Tab'), await press('Tab')]).toEqual([
		'uat-report__remove',
		'uat-report__file',
	]);
	expect(await press('Shift+Tab')).toBe('uat-report__remove');

	await dialog(page).getByLabel('What went wrong?').focus();
	await page.keyboard.type(`${MARKER}: sent from the keyboard`);
	expect([
		await press('Tab'),
		await press('Tab'),
		await press('Tab'),
	]).toEqual([
		'uat-report__remove',
		'uat-report__file',
		'uat-report__submit',
	]);

	await page.keyboard.press('Enter');
	await expect(status(page)).toHaveText(SENT);
	await expect(
		dialog(page).getByRole('button', { name: 'Close' })
	).toBeFocused();

	await page.keyboard.press('Enter');
	await expect(dialog(page)).toBeHidden();
	await expect(toggle(page)).toBeFocused();
});

test('while the panel is open its footer opens the dialog too, stays in reach and opens it above the panel', async ({
	page,
	site,
}) => {
	const { id } = await site.createChecklist(
		'Long',
		Array.from({ length: 25 }, (_, i) => `Step number ${i + 1}`)
	);
	const footerButton = panel(page).locator('.uat-panel__report');

	await openChecklist(page, id);
	// The admin bar sits above the panel, so its item stays in reach as well.
	await expect(toggle(page)).toBeVisible();

	// The list outruns the panel, so the footer is pinned over it rather than at its end.
	expect(
		await panel(page).evaluate((el) => el.scrollHeight - el.clientHeight)
	).toBeGreaterThan(0);

	for (const position of [0, 0.5, 1]) {
		await panel(page).evaluate((el, at) => {
			el.scrollTop = (el.scrollHeight - el.clientHeight) * at;
		}, position);
		await expect(footerButton).toBeInViewport({ ratio: 1 });
		expect(await isTopmost(footerButton)).toBe(true);
	}

	await footerButton.click();
	await expect(dialog(page)).toBeVisible();
	await expect(dialog(page).getByLabel('What went wrong?')).toBeFocused();
	expect(await isTopmost(dialog(page))).toBe(true);
	await expect(panel(page)).toBeVisible();

	await page.keyboard.press('Escape');
	await expect(dialog(page)).toBeHidden();
	await expect(footerButton).toBeFocused();
});

test('the pop-out window has neither the button nor the footer entry', async ({
	page,
	site,
}) => {
	// Without the always-on-top window, which Playwright cannot drive, Chromium takes the named-window path.
	await page.addInitScript(() =>
		Object.defineProperty(window, 'documentPictureInPicture', {
			value: undefined,
		})
	);
	const { id } = await site.createChecklist('Pop out', ['A step']);

	await openChecklist(page, id);
	const opening = page.waitForEvent('popup');
	await panel(page).getByRole('link', { name: 'Open in new window' }).click();
	const popup = await opening;

	await expect(panel(popup)).toBeVisible();
	// The deferred scripts have all run by the time the page has loaded.
	await popup.waitForLoadState('load');
	await expect(toggle(popup)).toHaveCount(0);
	await expect(popup.locator('.uat-panel__report')).toHaveCount(0);
	await expect(dialog(popup)).toHaveCount(0);
	expect(
		await popup.evaluate(() => window.hmUatChecklist.report)
	).toBeUndefined();
});

test.describe('as the manager', () => {
	test.use({ storageState: storageState(USERS.manager) });

	test('the block editor has the report item, and it opens the dialog', async ({
		page,
	}) => {
		await page.goto('/wp-admin/post-new.php?post_type=page');
		await expect(page.locator('[name="editor-canvas"]')).toBeVisible();
		await page.waitForLoadState('load');

		// Full-screen mode hides the admin bar, so the click is dispatched rather than made with the mouse.
		await expect(toggle(page)).toHaveCount(1);
		await toggle(page).dispatchEvent('click');
		await expect(dialog(page)).toBeVisible();
	});

	test('a row action moves a report without a page load, and a filtered list drops the row and updates its counts', async ({
		browser,
		reports,
	}) => {
		const first = await reports.send('first');
		const second = await reports.send('second');
		const manager = await openList(browser);
		const errors = collectErrors(manager);
		await manager.reload();

		expect(
			await manager.evaluate(() => typeof window.hmUatReportAdmin)
		).toBe('object');
		expect(await manager.evaluate(() => typeof window.wp.a11y)).toBe(
			'object'
		);
		for (const column of [
			'Report',
			'Reporter',
			'Page',
			'Status',
			'Screenshot',
			'Reported',
		]) {
			await expect(
				manager.locator('thead th', { hasText: column }).first()
			).toBeVisible();
		}

		await markPage(manager);
		const navigated = [];
		manager.on('framenavigated', (frame) => navigated.push(frame.url()));

		await row(manager, first).hover();
		await row(manager, first)
			.getByRole('button', { name: 'Mark as Investigating' })
			.click();
		await expect(
			row(manager, first).locator('.column-uat_status')
		).toHaveText('Investigating');
		await expect(manager.locator('#a11y-speak-polite')).toContainText(
			'Report moved to Investigating.'
		);
		// The row's actions now offer the two statuses it is not in.
		await expect(
			row(manager, first).getByRole('button', {
				name: 'Mark as Investigating',
			})
		).toHaveCount(0);
		await expect(
			row(manager, first).getByRole('button', { name: 'Mark as Done' })
		).toHaveCount(1);
		expect(await isSamePage(manager)).toBe(true);
		expect(navigated).toEqual([]);

		// The Pending view: the row leaves it, and the counts follow.
		await manager.goto(
			`${LIST}&s=${encodeURIComponent(MARKER)}&post_status=pending`
		);
		await expect(row(manager, first)).toHaveCount(0);
		await expect(row(manager, second)).toHaveCount(1);
		await markPage(manager);
		const pending = await viewCount(manager, 'pending');
		const done = await viewCount(manager, 'uat-done');

		await row(manager, second).hover();
		await row(manager, second)
			.getByRole('button', { name: 'Mark as Done' })
			.click();
		await expect(row(manager, second)).toHaveCount(0);
		await expect
			.poll(() => viewCount(manager, 'pending'))
			.toBe(pending - 1);
		await expect.poll(() => viewCount(manager, 'uat-done')).toBe(done + 1);
		expect(await isSamePage(manager)).toBe(true);
		expect(errors).toEqual([]);
		await manager.close();
	});

	test('the bulk action moves the checked reports and says how many', async ({
		browser,
		reports,
	}) => {
		const ids = [
			await reports.send('one'),
			await reports.send('two'),
			await reports.send('three'),
		];
		const manager = await openList(browser);
		const errors = collectErrors(manager);
		await manager.reload();
		await markPage(manager);

		await row(manager, ids[0]).locator('input[name="post[]"]').check();
		await row(manager, ids[1]).locator('input[name="post[]"]').check();
		await manager
			.locator('#bulk-action-selector-top')
			.selectOption({ label: 'Mark as Done' });
		await manager.locator('#doaction').click();

		const notice = manager.locator('.uat-report-admin__notice');
		await expect(notice).toHaveClass(/\bnotice-success\b/);
		await expect(notice).toHaveText(/2 reports moved to Done\./);
		await expect(manager.locator('#a11y-speak-polite')).toContainText(
			'2 reports moved to Done.'
		);
		for (const id of ids.slice(0, 2)) {
			await expect(
				row(manager, id).locator('.column-uat_status')
			).toHaveText('Done');
		}
		await expect(
			row(manager, ids[2]).locator('.column-uat_status')
		).toHaveText('Pending');
		expect(await isSamePage(manager)).toBe(true);
		expect(errors).toEqual([]);
		await manager.close();
	});

	test('the edit screen changes the status from its select and shows the read-only details', async ({
		page,
		reports,
	}) => {
		const id = await reports.send('edit screen', PNG);
		const errors = collectErrors(page);

		await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
		await expect(page.locator('.uat-report-admin__description')).toHaveText(
			`${MARKER}: edit screen`
		);
		await expect(
			page.locator('.uat-report-admin__screenshot img')
		).toHaveCount(1);
		await markPage(page);

		await page
			.locator('#uat-report-status-select')
			.selectOption('uat-done');
		await page.getByRole('button', { name: 'Update status' }).click();

		await expect(page.locator('.uat-report-admin__notice')).toHaveText(
			/Report moved to Done\./
		);
		await expect(page.locator('.uat-report-admin__current')).toHaveText(
			'Done'
		);
		await expect(page.locator('#a11y-speak-polite')).toContainText(
			'Report moved to Done.'
		);
		expect(await isSamePage(page)).toBe(true);
		expect(errors).toEqual([]);
	});

	test('the read-only report page shows the whole report and has no control to change it', async ({
		page,
		reports,
	}) => {
		const id = await reports.send('read only', PNG);

		await page.goto(
			`/wp-admin/admin.php?page=uat-report-view&report=${id}`
		);
		const details = page.locator('.uat-report-admin');

		await expect(
			details.locator('.uat-report-admin__description')
		).toHaveText(`${MARKER}: read only`);
		await expect(
			details.locator('.uat-report-admin__screenshot img')
		).toHaveCount(1);
		await expect(details.locator('.uat-report-admin__current')).toHaveText(
			'Pending'
		);
		await expect(details.locator('select, button, input')).toHaveCount(0);
	});
});

test('a tester cannot open the Reports list, a report or the status routes', async ({
	page,
	reports,
}) => {
	const id = await reports.send('hidden from the tester');

	const list = await page.goto(LIST);
	expect(list.status()).toBe(403);
	await expect(page.locator('#the-list')).toHaveCount(0);

	// The tester holds no capability to read results, so the read-only page is the manager's and editors' too.
	const view = await page.goto(
		`/wp-admin/admin.php?page=uat-report-view&report=${id}`
	);
	expect(view.status()).toBe(403);
	await expect(page.locator('.uat-report-admin')).toHaveCount(0);

	const moved = await reports.post(`${ROUTE}/${id}/status`, {
		data: { status: 'uat-done' },
	});
	expect(moved.status()).toBe(403);
});
