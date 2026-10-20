// Shared by the Node scripts: where the site is, WP-CLI, signing in as a test user, and opening the editor.
// config.py reads the same environment variables for the Python scripts and documents them.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

if (!process.env.UAT_URL) {
	console.error(
		"Set UAT_URL to the site's home URL, for example UAT_URL=http://localhost:8888/"
	);
	process.exit(1);
}

export const UAT_URL = process.env.UAT_URL.replace(/\/$/, '') + '/';
export const BASE = UAT_URL.replace(/\/$/, '');

const url = new URL(UAT_URL);
const SITE_KEY = (url.host + url.pathname)
	.toLowerCase()
	.replace(/[^a-z0-9]+/g, '-')
	.replace(/^-|-$/g, '');

export const CACHE =
	process.env.UAT_CACHE || join(homedir(), '.cache', 'hm-uat', SITE_KEY);

// Created by ensure-test-users.sh. Never sign in as a real tester's account: its results would be overwritten.
export const TESTER = 'uattestbot';
export const ADMIN = 'uattestadmin';

/**
 * Runs WP-CLI against the site and returns its trimmed stdout.
 *
 * @param {string[]} args  WP-CLI arguments.
 * @param {string}   input Text for stdin.
 * @return {string} Output.
 */
export function wp(args, input = '') {
	const [command, ...prefix] = (process.env.UAT_WP || 'wp')
		.trim()
		.split(/\s+/);

	return execFileSync(command, [...prefix, `--url=${UAT_URL}`, ...args], {
		input,
		encoding: 'utf8',
		stdio: ['pipe', 'pipe', 'ignore'],
	}).trim();
}

/**
 * Signs a Playwright page in through the login form.
 *
 * @param {import('playwright').Page} page Page to sign in.
 * @param {string}                    user TESTER or ADMIN.
 */
export async function login(page, user) {
	const credentials = JSON.parse(
		readFileSync(join(CACHE, 'credentials.json'), 'utf8')
	);

	await page.goto(`${BASE}/wp-login.php`);
	await page.fill('#user_login', user);
	await page.fill('#user_pass', credentials[user]);
	await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

/**
 * Waits for the block editor to load its post, and returns the post's ID.
 *
 * @param {import('playwright').Page} page Page with the editor open.
 * @return {Promise<string>} Post ID.
 */
export async function editorPostId(page) {
	const handle = await page.waitForFunction(() =>
		window.wp?.data?.select('core/editor')?.getCurrentPostId()
	);
	return String(await handle.jsonValue());
}

/**
 * Turns off the post editor's welcome guide, which would cover the screen.
 *
 * @param {import('playwright').Page} page Page with the post editor open.
 */
export async function dismissWelcomeGuide(page) {
	await page.evaluate(() =>
		window.wp.data
			.dispatch('core/preferences')
			.set('core/edit-post', 'welcomeGuide', false)
	);
}

/**
 * Hides the admin bar on a front-end capture.
 *
 * @param {import('playwright').Page} page Page.
 */
export async function hideAdminBar(page) {
	await page.addStyleTag({
		content:
			'#wpadminbar { display: none !important; } html { margin-top: 0 !important; --wp-admin--admin-bar--height: 0px !important; }',
	});
}

/**
 * Hides the banners some local environments draw over every screen, listed in UAT_HIDE as CSS selectors.
 *
 * @param {import('playwright').Page|import('playwright').Frame} page Page or frame.
 */
export async function hideEnvironmentChrome(page) {
	const selectors = (process.env.UAT_HIDE || '#vip-non-prod-bar')
		.split(',')
		.map((selector) => selector.trim())
		.filter(Boolean);

	if (selectors.length) {
		await page.addStyleTag({
			content: `${selectors.join(',')} { display: none !important; }`,
		});
	}
}
