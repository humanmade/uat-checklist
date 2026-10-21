/*
 * Shared by the browser tests: the site's address and users, a `site` fixture that publishes checklists and pages
 * as the UAT manager and deletes them after the test, the panel's locators, and the manager's Mark fixed flow.
 */
import path from 'node:path';
import { test as base, expect } from '@playwright/test';

export const PORT = 9434;
export const BASE_URL = `http://127.0.0.1:${PORT}`;

// Created by tests/e2e/blueprint.json. Only the manager's email is at a staff domain.
export const USERS = { manager: 'uat-manager', tester: 'uat-tester' };
export const PASSWORD = 'password';

const BLOCK_PREFIX = 'uat-checklist';

/**
 * @param {string} user User login.
 * @return {string} Path of the user's saved login, written by auth.setup.js.
 */
export function storageState(user) {
	return path.join(__dirname, '.auth', `${user}.json`);
}

/**
 * Returns the plugin's block markup, self-closing when it has no inner blocks.
 *
 * @param {string}  name       Block name without the plugin prefix.
 * @param {Object}  attributes Block attributes.
 * @param {string=} inner      Inner blocks' markup.
 * @return {string} Block markup.
 */
function block(name, attributes, inner) {
	const opener = `wp:${BLOCK_PREFIX}/${name} ${JSON.stringify(attributes)}`;

	return inner === undefined
		? `<!-- ${opener} /-->`
		: `<!-- ${opener} -->${inner}<!-- /wp:${BLOCK_PREFIX}/${name} -->`;
}

export const test = base.extend({
	site: async ({ playwright, baseURL }, use) => {
		const request = await playwright.request.newContext({
			baseURL,
			storageState: storageState(USERS.manager),
		});
		const nonce = await (
			await request.get('/wp-admin/admin-ajax.php?action=rest-nonce')
		).text();
		const created = [];
		const uploads = [];

		const rest = async (method, route, data) => {
			const response = await request.fetch(`/wp-json/wp/v2/${route}`, {
				method,
				headers: { 'X-WP-Nonce': nonce },
				data,
			});

			if (!response.ok()) {
				throw new Error(
					`${method} ${route}: ${response.status()} ${await response.text()}`
				);
			}

			return response.json();
		};

		const publish = async (route, fields) => {
			const post = await rest('POST', route, {
				status: 'publish',
				...fields,
			});
			created.push(`${route}/${post.id}`);

			return post;
		};

		await use({
			createPage: (title) => publish('pages', { title }),

			/**
			 * Publishes a checklist of one section holding a required step for each text given.
			 *
			 * @param {string}   title            Checklist title.
			 * @param {string[]} steps            Each step's text.
			 * @param {number}   instructionsPage Instructions page ID, or 0 for none.
			 * @return {Promise<{id: number, stepIds: string[]}>} The checklist and its step IDs, in order.
			 */
			async createChecklist(title, steps, instructionsPage = 0) {
				const stepIds = steps.map(() => crypto.randomUUID());
				const markup = steps
					.map((step, i) =>
						block('step', {
							stepId: stepIds[i],
							step,
							expected: 'It works',
						})
					)
					.join('');
				const post = await publish('uat-checklists', {
					title,
					content: block(
						'checklist',
						{},
						block('section', { title: 'Checks' }, markup)
					),
					meta: { _uat_instructions_page: instructionsPage },
				});

				return { id: post.id, stepIds };
			},

			getChecklist: (id) =>
				rest('GET', `uat-checklists/${id}?context=edit`),

			/**
			 * Deletes after the test every upload whose title holds the given text. An upload made in the editor
			 * never passes through this fixture, so it is found by name once the test is over.
			 *
			 * @param {string} name Text the upload's title holds.
			 */
			deleteUploadsNamed: (name) => uploads.push(name),
		});

		const searches = await Promise.allSettled(
			uploads.map((name) =>
				rest(
					'GET',
					`media?search=${encodeURIComponent(name)}&_fields=id`
				)
			)
		);
		for (const { value = [] } of searches) {
			created.push(...value.map(({ id }) => `media/${id}`));
		}

		// Every delete is tried and the request disposed before a failed delete fails the test.
		const deletes = await Promise.allSettled(
			created.map((route) => rest('DELETE', `${route}?force=true`))
		);
		await request.dispose();
		const failed = [...searches, ...deletes].find(
			({ status }) => status === 'rejected'
		);

		if (failed) {
			throw failed.reason;
		}
	},
});

export { expect };

/**
 * @param {import('@playwright/test').Page} page Page.
 * @return {import('@playwright/test').Locator} The UAT item in the admin bar, which opens the panel.
 */
export function adminBarItem(page) {
	return page.locator('#wp-admin-bar-uat-checklist > .ab-item');
}

/**
 * @param {import('@playwright/test').Page} page Page.
 * @return {import('@playwright/test').Locator} The panel.
 */
export function panel(page) {
	return page.locator('#uat-panel');
}

/**
 * @param {import('@playwright/test').Page} page Page.
 * @return {import('@playwright/test').Locator} The panel's checklist picker.
 */
export function picker(page) {
	return page.locator('#uat-panel-select');
}

/**
 * @param {import('@playwright/test').Page} page   Page.
 * @param {string}                          stepId Step ID.
 * @return {import('@playwright/test').Locator} The step's list item in the panel.
 */
export function panelStep(page, stepId) {
	return panel(page)
		.locator('.uat-checklist__step')
		.filter({ has: page.locator(`form[data-step-id="${stepId}"]`) });
}

/**
 * Opens the panel from the home page and picks a checklist in it.
 *
 * @param {import('@playwright/test').Page} page        Page.
 * @param {number}                          checklistId Checklist ID.
 */
export async function openChecklist(page, checklistId) {
	await page.goto('/');
	await adminBarItem(page).click();
	await picker(page).selectOption(String(checklistId));
	await expect(
		panel(page).locator(`form[data-checklist-id="${checklistId}"]`).first()
	).toBeVisible();
}

/**
 * Picks Pass or Fail on a step, with a comment when given one, and waits for the panel to confirm the save.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {string}                          stepId  Step ID.
 * @param {'Pass'|'Fail'}                   result  Result.
 * @param {string}                          comment Comment, or '' for none.
 */
export async function saveResult(page, stepId, result, comment = '') {
	const step = panelStep(page, stepId);
	const choice = step.getByLabel(result, { exact: true });

	await choice.check();
	await step.getByLabel('Comment').fill(comment);
	const saved = page.waitForResponse(
		(response) =>
			decodeURIComponent(response.url()).includes(
				'uat-checklist/v1/results'
			) && response.request().method() === 'POST'
	);
	await step.getByRole('button', { name: 'Save result' }).click();
	await saved;
	// The badge alone may already read the result from an earlier save. The button is busy until this one lands, and
	// the step is updated in the same turn it is freed.
	await expect(step.locator('.uat-checklist__save')).not.toHaveAttribute(
		'aria-disabled'
	);
	await expect(step.locator('.uat-checklist__badge')).toHaveText(result);
}

/**
 * Marks a step's Fail fixed from the Results list, as the UAT manager in a page of their own.
 *
 * @param {import('@playwright/test').Browser} browser     Browser.
 * @param {number}                             checklistId Checklist ID.
 * @param {string}                             step        The failed step's text.
 * @param {string}                             note        Note for testers.
 */
export async function markFixed(browser, checklistId, step, note) {
	const manager = await browser.newPage({
		storageState: storageState(USERS.manager),
	});
	await manager.goto(
		`/wp-admin/edit.php?post_type=uat_result&uat_checklist_id=${checklistId}`
	);
	const row = manager.locator('#the-list tr', { hasText: step });
	await row.hover();
	await row.getByRole('link', { name: 'Mark fixed' }).click();
	await manager.getByLabel('Note for testers').fill(note);
	await manager.getByRole('button', { name: 'Mark fixed' }).click();
	await expect(manager.locator('.notice-success')).toBeVisible();
	await manager.close();
}
