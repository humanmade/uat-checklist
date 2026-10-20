/*
 * Recording a result from the panel: the step form's validation, what a save changes on screen, and the retest a
 * developer's Mark fixed asks for.
 */
import {
	expect,
	markFixed,
	openChecklist,
	panel,
	panelStep,
	picker,
	saveResult,
	test,
} from './utils';

test('a Fail is refused without a comment, before anything is sent', async ({
	page,
	site,
}) => {
	const {
		id,
		stepIds: [stepId],
	} = await site.createChecklist('Fail needs a comment', ['Open the page']);
	const posts = [];
	page.on('request', (request) => {
		if (request.method() === 'POST' && request.url().includes('/results')) {
			posts.push(request);
		}
	});

	await openChecklist(page, id);
	const step = panelStep(page, stepId);
	const comment = step.getByLabel('Comment');
	await step.getByLabel('Fail', { exact: true }).check();
	await step.getByRole('button', { name: 'Save result' }).click();

	await expect(step.locator('.uat-checklist__status')).toHaveText(
		'Add a comment saying what went wrong.'
	);
	await expect(comment).toHaveAttribute('aria-invalid', 'true');
	await expect(comment).toBeFocused();
	await expect(step.locator('.uat-checklist__badge')).toBeHidden();
	expect(posts).toHaveLength(0);

	await comment.fill('The page is blank.');
	await expect(comment).not.toHaveAttribute('aria-invalid');
});

test('saving a result counts it and moves on to the next step still to test', async ({
	page,
	site,
}) => {
	const { id, stepIds } = await site.createChecklist('Save and move on', [
		'First',
		'Second',
		'Third',
	]);
	const [first, second, third] = stepIds.map((stepId) =>
		panelStep(page, stepId)
	);
	const done = panel(page).locator('.uat-checklist__done');
	const isOpen = (step) =>
		expect(step.locator('details')).toHaveAttribute('open', '');

	await openChecklist(page, id);
	await second.locator('summary').click();
	await saveResult(page, stepIds[1], 'Pass');

	await expect(second).toHaveAttribute('data-result', 'pass');
	await expect(done).toHaveText('1');
	await expect(panel(page).locator('.uat-checklist__meter')).toHaveJSProperty(
		'value',
		1
	);
	await expect(picker(page).locator('option:checked')).toHaveText(
		'Save and move on (1 / 3 required)'
	);
	await isOpen(third);
	await expect(third.locator('summary')).toBeFocused();

	// From the last step it wraps round to the top.
	await saveResult(page, stepIds[2], 'Pass');
	await isOpen(first);

	// With every step tested, nothing opens and the saved step keeps focus.
	await saveResult(page, stepIds[0], 'Pass');
	await expect(first.locator('details')).not.toHaveAttribute('open');
	await expect(first.locator('summary')).toBeFocused();
	await expect(done).toHaveText('3');
});

test('a Fail marked fixed asks the tester for a retest', async ({
	page,
	browser,
	site,
}) => {
	const {
		id,
		stepIds: [stepId, otherId],
	} = await site.createChecklist('Retest after a fix', [
		'Press the button',
		'Read the message',
	]);
	const step = panelStep(page, stepId);
	const done = panel(page).locator('.uat-checklist__done');

	await openChecklist(page, id);
	await saveResult(page, stepId, 'Fail', 'Nothing happens.');

	await markFixed(browser, id, 'Press the button', 'Wired up the handler.');

	// The panel was left open, so it reopens on the same checklist.
	await page.reload();
	await expect(step).toHaveAttribute('data-result', 'retest');
	await expect(step.locator('.uat-checklist__badge')).toHaveText('Retest');
	await expect(step.locator('.uat-checklist__retest')).toContainText(
		'Wired up the handler.'
	);
	await expect(done).toHaveText('0');

	// A step waiting for a retest is still to test: saving another leaves it uncounted, and opens it next.
	await panelStep(page, otherId).locator('summary').click();
	await saveResult(page, otherId, 'Pass');
	await expect(done).toHaveText('1');
	await expect(step.locator('details')).toHaveAttribute('open', '');

	await saveResult(page, stepId, 'Pass');
	await expect(step.locator('.uat-checklist__retest')).toHaveCount(0);
	await expect(done).toHaveText('2');
});
