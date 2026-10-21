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
	const listRequests = [];
	page.on('request', (request) => {
		if (
			decodeURIComponent(request.url()).includes(
				'uat-checklist/v1/retests'
			)
		) {
			listRequests.push(request);
		}
	});

	await openChecklist(page, id);
	// The panel's own load is issued before the checklist's, which openChecklist() waited on, so it is counted.
	const loadsBeforeSave = listRequests.length;
	expect(loadsBeforeSave).toBeGreaterThan(0);
	await saveResult(page, stepId, 'Fail', 'Nothing happens.');
	// An empty list renders nothing, so no element shows the refresh after the save has landed: its answer is read
	// instead.
	await expect
		.poll(() => listRequests.length)
		.toBeGreaterThan(loadsBeforeSave);
	const refreshed = await listRequests[loadsBeforeSave].response();
	expect(await refreshed.json()).toEqual([]);

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
