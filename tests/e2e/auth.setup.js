/*
 * Logs in each of the blueprint's users once and saves the session for the tests to start from.
 */
import { expect, test as setup } from '@playwright/test';

import { PASSWORD, storageState, USERS } from './utils';

for (const user of Object.values(USERS)) {
	setup(`log in as ${user}`, async ({ request }) => {
		setup.setTimeout(90_000);
		// Retried, as Gutenberg's setupRest() retries its login: Playground answers requests, and so passes the
		// webServer check, once WordPress boots and before the blueprint has created this user.
		await expect(async () => {
			// Posts the form's fields, as Gutenberg's login helper does. Filling the form races its 200ms autofocus,
			// which can send the password into the username.
			const response = await request.post('/wp-login.php', {
				form: { log: user, pwd: PASSWORD },
			});
			// A refused login answers 200 with the form again, so only the redirect into wp-admin shows it worked.
			expect(response.url()).toMatch(/\/wp-admin\//);
		}).toPass({ timeout: 60_000 });
		await request.storageState({ path: storageState(user) });
	});
}
