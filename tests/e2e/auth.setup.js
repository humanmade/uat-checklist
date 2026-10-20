/*
 * Logs in each of the blueprint's users once and saves the session for the tests to start from.
 */
import { test as setup } from '@playwright/test';

import { PASSWORD, storageState, USERS } from './utils';

for (const user of Object.values(USERS)) {
	setup(`log in as ${user}`, async ({ page }) => {
		await page.goto('/wp-login.php');
		await page.getByLabel('Username or Email Address').fill(user);
		await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
		await page.getByRole('button', { name: 'Log In' }).click();
		await page.waitForURL(/\/wp-admin\//);
		await page.context().storageState({ path: storageState(user) });
	});
}
