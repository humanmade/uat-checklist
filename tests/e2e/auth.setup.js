/*
 * Logs in each of the blueprint's users once and saves the session for the tests to start from.
 */
import { expect, test as setup } from '@playwright/test';

import { PASSWORD, storageState, USERS } from './utils';

for (const user of Object.values(USERS)) {
	setup(`log in as ${user}`, async ({ page }) => {
		await page.goto('/wp-login.php');
		const username = page.getByLabel('Username or Email Address');
		// wp-login.php focuses and selects the username 200ms after its script runs. Landing mid-fill, that sends
		// the password into the username and leaves the required password empty, so the form never submits.
		await expect(username).toBeFocused();
		await username.fill(user);
		await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
		await page.getByRole('button', { name: 'Log In' }).click();
		await page.waitForURL(/\/wp-admin\//);
		await page.context().storageState({ path: storageState(user) });
	});
}
