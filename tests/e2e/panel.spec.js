/*
 * The panel itself: when it opens by itself, what it remembers from page to page, and the pop-out window.
 */
import {
	adminBarItem,
	expect,
	openChecklist,
	panel,
	picker,
	test,
} from './utils';

/**
 * Waits until the panel script has restored the panel shut. A missing panel also counts as hidden, so the
 * admin bar item's aria-pressed, which only the script sets, proves the panel was built.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function expectShut(page) {
	await expect(adminBarItem(page)).toHaveAttribute('aria-pressed', 'false');
	await expect(panel(page)).toBeHidden();
}

test('the panel opens by itself on the first visit to an instructions page, on that checklist', async ({
	page,
	context,
	site,
}) => {
	const instructions = await site.createPage('Button instructions');
	const buttons = await site.createChecklist(
		'Buttons',
		['Add a button'],
		instructions.id
	);
	const other = await site.createChecklist('Other', ['Something else']);

	await page.goto(instructions.link);
	await expect(panel(page)).toBeVisible();
	await expect(picker(page)).toHaveValue(String(buttons.id));

	await picker(page).selectOption(String(other.id));
	await panel(page).getByRole('button', { name: 'Close' }).click();

	// A new tab starts with no open state of its own, and the panel has been seen, so it waits to be asked.
	const secondTab = await context.newPage();
	await secondTab.goto(instructions.link);
	await expect(adminBarItem(secondTab)).toBeVisible();
	await expectShut(secondTab);

	// On its instructions page the checklist wins over the one chosen last.
	await adminBarItem(secondTab).click();
	await expect(picker(secondTab)).toHaveValue(String(buttons.id));
});

for (const [outcome, settle, settled] of [
	['answers', (route) => route.continue(), 'requestfinished'],
	['fails', (route) => route.abort(), 'requestfailed'],
]) {
	test(`a checklist picked while another loads stays shown when the other ${outcome} late`, async ({
		page,
		site,
	}) => {
		const instructions = await site.createPage('Slow instructions');
		const slow = await site.createChecklist(
			'Slow',
			['Slow step'],
			instructions.id
		);
		const picked = await site.createChecklist('Picked', ['Picked step']);
		const stepForm = (id) =>
			panel(page).locator(
				`.uat-checklist__result[data-checklist-id="${id}"]`
			);
		const slowRoute = new RegExp(
			`/uat-checklist/v1/checklists/${slow.id}(\\?|$)`
		);
		let release;
		const held = new Promise((resolve) => (release = resolve));
		await page.route(slowRoute, async (route) => {
			await held;
			await settle(route);
		});

		// The first visit to its instructions page opens the panel on the slow checklist, whose request is held.
		await page.goto(instructions.link);
		await picker(page).selectOption(String(picked.id));
		await expect(stepForm(picked.id)).toBeVisible();

		const slowSettled = page.waitForEvent(settled, (request) =>
			slowRoute.test(request.url())
		);
		release();
		await slowSettled;
		// The panel handles the late request a few tasks after it settles; two frames is ample.
		await page.evaluate(
			() =>
				new Promise((resolve) =>
					window.requestAnimationFrame(() =>
						window.requestAnimationFrame(resolve)
					)
				)
		);

		await expect(picker(page)).toHaveValue(String(picked.id));
		await expect(stepForm(picked.id)).toBeVisible();
		await expect(stepForm(slow.id)).toHaveCount(0);
	});
}

test('the panel stays open from page to page, on the checklist last chosen, until closed', async ({
	page,
	site,
}) => {
	const elsewhere = await site.createPage('Somewhere else');
	const chosen = await site.createChecklist('Chosen', ['A step']);

	await openChecklist(page, chosen.id);
	await page.goto(elsewhere.link);
	await expect(panel(page)).toBeVisible();
	await expect(picker(page)).toHaveValue(String(chosen.id));

	await picker(page).focus();
	await page.keyboard.press('Escape');
	await expect(panel(page)).toBeHidden();
	await expect(adminBarItem(page)).toBeFocused();

	await page.goto('/');
	await expect(adminBarItem(page)).toBeVisible();
	await expectShut(page);
});

test('Open in new window moves the checklist to a pop-out, and its Close shuts the window', async ({
	page,
	site,
}) => {
	// Without the always-on-top window, which Playwright cannot drive, Chromium takes the named-window path that
	// Firefox and Safari always take.
	await page.addInitScript(() =>
		Object.defineProperty(window, 'documentPictureInPicture', {
			value: undefined,
		})
	);
	const { id } = await site.createChecklist('Pop out', ['A step']);
	// The admin bar's list item, which carries the class; adminBarItem() is the link inside it.
	const menuItem = page.locator('#wp-admin-bar-uat-checklist');

	await openChecklist(page, id);
	const opening = page.waitForEvent('popup');
	await panel(page).getByRole('link', { name: 'Open in new window' }).click();
	const popup = await opening;

	await expect(panel(popup)).toBeVisible();
	await expect(picker(popup)).toHaveValue(String(id));
	await expect(panel(page)).toBeHidden();
	await expect(menuItem).toHaveClass(/\bis-in-window\b/);

	const closing = popup.waitForEvent('close');
	// The window can close before the click's own input events are acknowledged, and the click then throws.
	await panel(popup)
		.getByRole('button', { name: 'Close' })
		.click()
		.catch((error) => {
			if (!popup.isClosed()) {
				throw error;
			}
		});
	await closing;
	await expect(menuItem).not.toHaveClass(/\bis-in-window\b/);
});
