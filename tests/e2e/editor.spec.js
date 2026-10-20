/*
 * A checklist in the block editor: the step IDs results are stored against, and the Instructions page setting.
 */
import { expect, storageState, test, USERS } from './utils';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

test.use({ storageState: storageState(USERS.manager) });

/**
 * Opens a checklist in the editor once its steps are in the block editor, with the welcome guide off so nothing
 * covers the sidebar.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {number}                          id   Checklist ID.
 */
async function openEditor(page, id) {
	await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
	// Waits on the blocks the tests act on: a post ID in the editor store does not show they have reached the block
	// editor's store.
	await page.waitForFunction(
		() =>
			window.wp?.data
				?.select('core/block-editor')
				?.getBlocksByName('uat-checklist/step').length > 0
	);
	await page.evaluate(() =>
		window.wp.data
			.dispatch('core/preferences')
			.set('core/edit-post', 'welcomeGuide', false)
	);
}

test('a step added or duplicated in the editor gets an ID of its own, and the original keeps its ID', async ({
	page,
	site,
}) => {
	const {
		id,
		stepIds: [original],
	} = await site.createChecklist('Step IDs', ['Existing step']);
	await openEditor(page, id);

	await page.evaluate(() => {
		const { dispatch, select } = window.wp.data;
		const blocks = select('core/block-editor');
		const [section] = blocks.getBlocksByName('uat-checklist/section');
		const [step] = blocks.getBlocksByName('uat-checklist/step');

		dispatch('core/block-editor').insertBlock(
			window.wp.blocks.createBlock('uat-checklist/step'),
			undefined,
			section
		);
		return dispatch('core/block-editor').duplicateBlocks([step]);
	});

	const stepIds = () =>
		page.evaluate(() => {
			const blocks = window.wp.data.select('core/block-editor');

			return blocks
				.getBlocksByName('uat-checklist/step')
				.map((clientId) => blocks.getBlockAttributes(clientId).stepId);
		});
	await expect
		.poll(async () => {
			const ids = await stepIds();
			return new Set(ids.filter((stepId) => UUID.test(stepId))).size;
		})
		.toBe(3);
	const ids = await stepIds();
	expect(ids[0]).toBe(original);

	await page.evaluate(() =>
		window.wp.data.dispatch('core/editor').savePost()
	);
	const saved = (await site.getChecklist(id)).content.raw;
	for (const stepId of ids) {
		expect(saved).toContain(`"stepId":"${stepId}"`);
	}
});

test('the Instructions page setting saves the page chosen', async ({
	page,
	site,
}) => {
	const instructions = await site.createPage('Checkout instructions');
	const { id } = await site.createChecklist('Checkout', ['Pay']);
	await openEditor(page, id);

	const panel = page.getByRole('button', { name: 'Instructions page' });
	if ((await panel.getAttribute('aria-expanded')) === 'false') {
		await panel.click();
	}
	await page.getByRole('combobox', { name: 'Page' }).fill('Checkout instr');
	await page.getByRole('option', { name: 'Checkout instructions' }).click();
	expect(
		await page.evaluate(
			() =>
				window.wp.data
					.select('core/editor')
					.getEditedPostAttribute('meta')._uat_instructions_page
		)
	).toBe(instructions.id);

	await page
		.getByRole('region', { name: 'Editor top bar' })
		.getByRole('button', { name: 'Save', exact: true })
		.click();
	// Waits on the editor's own notice, as Gutenberg's tests do, rather than polling the REST API for a fixed five
	// seconds a slow save outlasts.
	await page
		.getByRole('button', { name: 'Dismiss this notice' })
		.filter({ hasText: 'updated' })
		.waitFor();
	expect((await site.getChecklist(id)).meta._uat_instructions_page).toBe(
		instructions.id
	);
});
