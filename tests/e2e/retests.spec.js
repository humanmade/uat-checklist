/*
 * The panel's Ready to retest section: a tester's Fail that a manager marked fixed is listed, opens its step when
 * chosen, and leaves the list once retested.
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

test('a fixed Fail is listed as ready to retest, opens its step, and leaves once retested', async ({
	page,
	browser,
	site,
}) => {
	const {
		id,
		stepIds: [stepId, otherId],
	} = await site.createChecklist('Retest list', [
		'Press the button',
		'Read the message',
	]);
	const { id: elsewhere } = await site.createChecklist('Somewhere else', [
		'Unrelated',
	]);
	const retests = panel(page).locator('.uat-panel__retests');
	const entry = retests.getByRole('button', { name: /Press the button/ });

	await openChecklist(page, id);
	await saveResult(page, stepId, 'Fail', 'Nothing happens.');
	await expect(retests).toHaveCount(0);

	await markFixed(browser, id, 'Press the button', 'Wired up the handler.');

	// Another checklist is showing, so choosing the entry has to switch back to the one it belongs to.
	await page.reload();
	await picker(page).selectOption(String(elsewhere));
	await expect(retests.locator('summary')).toHaveText('Ready to retest (1)');
	await expect(entry).toContainText('Retest list');
	await expect(entry).toContainText('Wired up the handler.');

	await entry.click();
	await expect(picker(page)).toHaveValue(String(id));
	await expect(panelStep(page, stepId).locator('details')).toHaveAttribute(
		'open',
		''
	);
	await expect(
		panelStep(page, otherId).locator('details')
	).not.toHaveAttribute('open');
	await expect(
		panelStep(page, stepId).locator('.uat-checklist__summary')
	).toBeFocused();

	await saveResult(page, stepId, 'Pass');
	await expect(retests).toHaveCount(0);
});
