/*
 * A tester's screenshot on a result: sent with Save result from the panel, refused unless it is an image, and shown
 * beside their comment in the Results list, on the Mark fixed page and in the retest notice.
 */
import {
	expect,
	openChecklist,
	panelStep,
	storageState,
	test,
	USERS,
} from './utils';

// A 1×1 PNG.
const PNG = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
	'base64'
);

test('a file named as an image that is not one is refused, and nothing is saved', async ({
	page,
	site,
}) => {
	const {
		id,
		stepIds: [stepId],
	} = await site.createChecklist('Refused screenshot', ['Press the button']);
	await openChecklist(page, id);
	const step = panelStep(page, stepId);

	await step.getByLabel('Fail', { exact: true }).check();
	await step.getByLabel('Comment').fill('Nothing happens.');
	// The browser takes the type from the name, so only the server, reading the content, can tell.
	await step.getByLabel('Screenshot').setInputFiles({
		name: 'shot.png',
		mimeType: 'image/png',
		buffer: Buffer.from('not an image'),
	});
	await step.getByRole('button', { name: 'Save result' }).click();

	await expect(step.locator('.uat-checklist__status')).toHaveClass(
		/is-error/
	);
	await expect(step.locator('.uat-checklist__badge')).toBeHidden();
});

test('a screenshot saved with a Fail shows by the comment in Results, on Mark fixed and in the retest notice', async ({
	page,
	browser,
	site,
}) => {
	const {
		id,
		stepIds: [stepId],
	} = await site.createChecklist('Screenshot on a Fail', [
		'Press the button',
	]);
	await openChecklist(page, id);
	const step = panelStep(page, stepId);

	await step.getByLabel('Fail', { exact: true }).check();
	await step.getByLabel('Comment').fill('The button does nothing.');
	await step.getByLabel('Screenshot').setInputFiles({
		name: 'button.png',
		mimeType: 'image/png',
		buffer: PNG,
	});
	await step.getByRole('button', { name: 'Save result' }).click();
	await expect(step.locator('.uat-checklist__badge')).toHaveText('Fail');

	const manager = await browser.newPage({
		storageState: storageState(USERS.manager),
	});
	await manager.goto(
		`/wp-admin/edit.php?post_type=uat_result&uat_checklist_id=${id}`
	);
	const row = manager.locator('#the-list tr', {
		hasText: 'Press the button',
	});
	await expect(
		row.locator('.column-uat_comment .uat-checklist__screenshot img')
	).toHaveAttribute('src', /\/wp-content\/uploads\//);

	await row.hover();
	await row.getByRole('link', { name: 'Mark fixed' }).click();
	await expect(
		manager.locator('.uat-checklist__screenshot img')
	).toHaveAttribute('src', /\/wp-content\/uploads\//);
	await manager.getByLabel('Note for testers').fill('Fixed the handler.');
	await manager.getByRole('button', { name: 'Mark fixed' }).click();
	await expect(manager.locator('.notice-success')).toBeVisible();
	await manager.close();

	// The panel was left open, so it reopens on the same checklist.
	await page.reload();
	await expect(
		panelStep(page, stepId).locator(
			'.uat-checklist__retest .uat-checklist__screenshot img'
		)
	).toHaveAttribute('src', /\/wp-content\/uploads\//);
});
