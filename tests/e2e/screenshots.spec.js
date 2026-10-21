/*
 * A tester's screenshot on a result: previewed when chosen, refused unless it is an image, sent with Save result, and
 * shown in the Results list, on the Mark fixed page and in the retest notice.
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

// Named as a PNG, so the browser reports it as one: only reading the content shows it is not.
const FAKE_PNG = {
	name: 'shot.png',
	mimeType: 'image/png',
	buffer: Buffer.from('not an image'),
};

test('the form refuses a file named as an image that is not one', async ({
	page,
	site,
}) => {
	const {
		id,
		stepIds: [stepId],
	} = await site.createChecklist('Refused in the form', ['Press the button']);
	await openChecklist(page, id);
	const step = panelStep(page, stepId);

	await step.getByLabel('Add a screenshot').setInputFiles(FAKE_PNG);

	await expect(step.locator('.uat-checklist__status')).toHaveText(
		'Choose a JPEG, PNG, GIF or WebP image.'
	);
	await expect(step.locator('.uat-checklist__preview')).toBeHidden();
	await expect(step.locator('.uat-checklist__upload')).toHaveText(
		'Add a screenshot'
	);

	// Saving without one clears the refusal, so the field does not stay marked invalid.
	const file = step.locator('.uat-checklist__file');
	await expect(file).toHaveAttribute('aria-invalid', 'true');
	await step.getByLabel('Pass', { exact: true }).check();
	await step.getByRole('button', { name: 'Save result' }).click();
	await expect(step.locator('.uat-checklist__badge')).toHaveText('Pass');
	await expect(file).not.toHaveAttribute('aria-invalid');
});

test('the route refuses a file that is not an image, and saves no result', async ({
	page,
	site,
}) => {
	const {
		id,
		stepIds: [stepId],
	} = await site.createChecklist('Refused by the route', [
		'Press the button',
	]);
	await openChecklist(page, id);
	const nonce = await (
		await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')
	).text();

	const response = await page.request.post(
		'/wp-json/uat-checklist/v1/results',
		{
			headers: { 'X-WP-Nonce': nonce },
			multipart: {
				checklist_id: String(id),
				step_id: stepId,
				status: 'fail',
				comment: 'Nothing happens.',
				screenshot: FAKE_PNG,
			},
		}
	);

	expect(response.status()).toBe(400);
	expect((await response.json()).code).toBe(
		'uat_checklist_screenshot_refused'
	);
	// The panel loads the checklist after the page, and a badge not yet rendered also counts as hidden.
	await page.reload();
	const step = panelStep(page, stepId);
	await expect(step.locator('.uat-checklist__summary')).toBeVisible();
	await expect(step.locator('.uat-checklist__badge')).toBeHidden();
});

test('a chosen screenshot is previewed above the button, which offers to replace it, until it is removed', async ({
	page,
	site,
}) => {
	const {
		id,
		stepIds: [stepId],
	} = await site.createChecklist('Preview', ['Press the button']);
	await openChecklist(page, id);
	const step = panelStep(page, stepId);
	const preview = step.locator('.uat-checklist__preview');

	await step.getByLabel('Add a screenshot').setInputFiles({
		name: 'button.png',
		mimeType: 'image/png',
		buffer: PNG,
	});
	await expect(preview.locator('img')).toHaveAttribute('src', /^blob:/);
	await expect(step.locator('.uat-checklist__upload')).toHaveText(
		'Replace screenshot'
	);

	await step.getByRole('button', { name: 'Remove screenshot' }).click();
	await expect(preview).toBeHidden();
	await expect(preview.locator('img')).toHaveCount(0);
	await expect(step.locator('.uat-checklist__upload')).toHaveText(
		'Add a screenshot'
	);
});

test('a screenshot saved with a Fail shows in Results, on Mark fixed and in the retest notice', async ({
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
	await step.getByLabel('Add a screenshot').setInputFiles({
		name: 'button.png',
		mimeType: 'image/png',
		buffer: PNG,
	});
	await step.getByRole('button', { name: 'Save result' }).click();
	await expect(step.locator('.uat-checklist__badge')).toHaveText('Fail');
	// The form keeps what was saved, with the stored screenshot in place of the chosen file.
	await expect(step.getByLabel('Comment')).toHaveValue(
		'The button does nothing.'
	);
	await expect(step.locator('.uat-checklist__preview img')).toHaveAttribute(
		'src',
		/\/wp-content\/uploads\//
	);

	// After a reload too, and an addition to the comment saves as a new result that keeps the screenshot. Every step
	// is done, so none opens by itself.
	await page.reload();
	const reloaded = panelStep(page, stepId);
	await reloaded.locator('.uat-checklist__summary').click();
	await expect(reloaded.getByLabel('Fail', { exact: true })).toBeChecked();
	await expect(reloaded.getByLabel('Comment')).toHaveValue(
		'The button does nothing.'
	);
	await reloaded
		.getByLabel('Comment')
		.fill('The button does nothing. Not even on mobile.');
	await reloaded.getByRole('button', { name: 'Save result' }).click();
	await expect(reloaded.locator('.uat-checklist__status')).toHaveText(
		/^Saved as Fail on [^.]+\.$/
	);

	const manager = await browser.newPage({
		storageState: storageState(USERS.manager),
	});
	await manager.goto(
		`/wp-admin/edit.php?post_type=uat_result&uat_checklist_id=${id}`
	);
	await expect(
		manager.locator(
			'#the-list .column-uat_screenshot .uat-checklist__screenshot img'
		)
	).toHaveCount(2);
	const row = manager.locator('#the-list tr', {
		hasText: 'Not even on mobile.',
	});
	await expect(
		row.locator('.column-uat_screenshot .uat-checklist__screenshot img')
	).toHaveAttribute('src', /\/wp-content\/uploads\//);
	await expect(row.locator('.column-uat_comment img')).toHaveCount(0);

	await row.hover();
	await row.getByRole('link', { name: 'Mark fixed' }).click();
	await expect(
		manager.locator('.uat-fixed__screenshot .uat-checklist__screenshot img')
	).toHaveAttribute('src', /\/wp-content\/uploads\//);
	await expect(
		manager.getByRole('link', { name: /Open full size/ })
	).toHaveAttribute('href', /\/wp-content\/uploads\//);
	await manager.getByLabel('Note for testers').fill('Fixed the handler.');
	await manager.getByRole('button', { name: 'Mark fixed' }).click();
	await expect(manager.locator('.notice-success')).toBeVisible();
	await manager.close();

	// The panel was left open, so it reopens on the same checklist.
	await page.reload();
	const retest = panelStep(page, stepId);
	await expect(
		retest.locator('.uat-checklist__retest .uat-checklist__screenshot img')
	).toHaveAttribute('src', /\/wp-content\/uploads\//);
	// The Fail shows in the notice, and the form starts blank for the retest.
	await expect(retest.getByLabel('Comment')).toHaveValue('');
	await expect(retest.getByLabel('Fail', { exact: true })).not.toBeChecked();
	await expect(retest.locator('.uat-checklist__preview img')).toHaveCount(0);
});

test('a screenshot removed from a saved result is not kept by the next save', async ({
	page,
	site,
}) => {
	const {
		id,
		stepIds: [stepId],
	} = await site.createChecklist('Screenshot removed', ['Press the button']);
	await openChecklist(page, id);
	const step = panelStep(page, stepId);

	await step.getByLabel('Fail', { exact: true }).check();
	await step.getByLabel('Comment').fill('The button does nothing.');
	await step.getByLabel('Add a screenshot').setInputFiles({
		name: 'button.png',
		mimeType: 'image/png',
		buffer: PNG,
	});
	await step.getByRole('button', { name: 'Save result' }).click();
	await expect(step.locator('.uat-checklist__preview img')).toHaveAttribute(
		'src',
		/\/wp-content\/uploads\//
	);

	// The save moved on and closed the step.
	await step.locator('.uat-checklist__summary').click();
	await step.getByRole('button', { name: 'Remove screenshot' }).click();
	await step.getByRole('button', { name: 'Save result' }).click();
	await expect(step.locator('.uat-checklist__status')).toHaveText(
		/^Saved as Fail on [^.]+\.$/
	);

	// The panel loads the checklist after the page, so the step is waited for before its preview is counted.
	await page.reload();
	const reloaded = panelStep(page, stepId);
	await expect(reloaded.locator('.uat-checklist__badge')).toHaveText('Fail');
	await expect(reloaded.locator('.uat-checklist__preview img')).toHaveCount(
		0
	);
});
