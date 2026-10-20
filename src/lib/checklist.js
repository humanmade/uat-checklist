/*
 * Behaviour inside a rendered checklist: the progress count, moving on after a save, and the pop-out window.
 */

import { __ } from '@wordpress/i18n';

export const SAVED_EVENT = 'uat-checklist:saved';

// Fragment on the "Open in new window" link that tells the pop-out to open its panel. Mirrored in
// inc/blocks.php as WINDOW_HASH.
export const WINDOW_HASH = 'uat-checklist';

// Width matches the pop-out panel's max-width in src/panel.scss. src/panel.js keeps the pop-out at it.
export const POPUP_WIDTH = 500;
const POPUP_HEIGHT = 900;
const POPUP_FEATURES = `popup,width=${POPUP_WIDTH},height=${POPUP_HEIGHT}`;
// Body class inc/blocks.php adds in the pop-out window view.
export const WINDOW_CLASS = 'uat-window';

/**
 * Returns window features for the instructions: a window filling the screen, so block pages show their desktop
 * layout.
 *
 * @return {string} Features for window.open().
 */
function pageFeatures() {
	const { availWidth, availHeight } = window.screen;

	return `popup,left=0,top=0,width=${availWidth},height=${availHeight}`;
}

/**
 * Opens the pop-out in a window under the link's name, reusing one already open under it.
 *
 * @param {HTMLAnchorElement} link     The "Open in new window" link.
 * @param {Function}          onPopOut Called once the window has opened.
 * @return {boolean} Whether the window opened; when it did not, the link is followed instead.
 */
function openWindow(link, onPopOut) {
	const popup = window.open(link.href, link.target, POPUP_FEATURES);

	if (!popup) {
		return false;
	}

	// A pop-out already open under this name is reused in place, possibly behind other windows.
	popup.focus();
	onPopOut();
	return true;
}

/**
 * Shows the pop-out in an always-on-top window, through the Document Picture-in-Picture API.
 *
 * That window cannot be navigated, so it holds the pop-out page in a frame, and the panel there works as it does in
 * a normal window. A second call reuses the window that is open and loads the new page into its frame.
 *
 * @param {string} url Pop-out page address.
 * @return {Promise<void>} Settles once the page is loading in the window.
 */
async function openOnTop(url) {
	const picture = window.documentPictureInPicture;
	let onTop = picture.window;

	if (!onTop) {
		onTop = await picture.requestWindow({
			width: POPUP_WIDTH,
			height: POPUP_HEIGHT,
		});

		// The window starts as an empty document with no stylesheet of its own, so this is its stylesheet.
		const style = onTop.document.createElement('style');
		style.textContent =
			'html,body{height:100%;margin:0}iframe{display:block;width:100%;height:100%;border:0}';

		const frame = onTop.document.createElement('iframe');
		frame.title = __('Testing checklist', 'uat-checklist');

		onTop.document.head.append(style);
		onTop.document.body.append(frame);
	}

	onTop.document.querySelector('iframe').src = url;
}

/**
 * Returns whether a step still needs a tester: it has no result, or its Fail was marked fixed and waits for a
 * retest.
 *
 * @param {HTMLElement} step Step.
 * @return {boolean} Whether the step needs testing.
 */
function needsTesting(step) {
	return !step.dataset.result || step.dataset.result === 'retest';
}

/**
 * Returns how many of a checklist's steps are done: tested, and not waiting for a retest.
 *
 * @param {HTMLElement} checklist      The `.uat-checklist` region.
 * @param {boolean}     [requiredOnly] Whether to count only the required steps.
 * @return {number} Steps done.
 */
export function countDone(checklist, requiredOnly = false) {
	const required = requiredOnly ? '[data-required]' : '';

	return [
		...checklist.querySelectorAll(`.uat-checklist__step${required}`),
	].filter((step) => !needsTesting(step)).length;
}

/**
 * Wires a rendered checklist's progress, save-and-advance and pop-out link.
 *
 * @param {HTMLElement} checklist        The `.uat-checklist` region.
 * @param {Object}      options          Options.
 * @param {Function}    options.onPopOut Called once the pop-out window has opened.
 */
export function bindChecklist(checklist, { onPopOut }) {
	const windowLink = checklist.querySelector('.uat-checklist__window');
	const pageLink = checklist.querySelector('.uat-checklist__page');

	if (pageLink) {
		const inWindow = document.body.classList.contains(WINDOW_CLASS);
		// In the main window on the page itself the link would reopen what the tester is reading.
		pageLink.hidden =
			!inWindow &&
			new URL(pageLink.href).pathname === window.location.pathname;

		pageLink.addEventListener('click', (event) => {
			const opened = window.open(
				pageLink.href,
				pageLink.target,
				pageFeatures()
			);

			if (opened) {
				event.preventDefault();
				opened.focus();
			}
		});
	}

	windowLink?.addEventListener('click', (event) => {
		// Chrome and Edge can keep the checklist above every other window; other browsers get a named window.
		if (window.documentPictureInPicture) {
			event.preventDefault();
			openOnTop(windowLink.href).then(onPopOut, () =>
				openWindow(windowLink, onPopOut)
			);
			return;
		}

		if (openWindow(windowLink, onPopOut)) {
			event.preventDefault();
		}
	});

	// Opening a step closes the open one, whose collapsing body can carry the clicked header out of view, so the
	// opened step scrolls to the top once the layout has settled. Closing a step leaves the tester where they are.
	checklist.addEventListener('click', (event) => {
		const summary = event.target.closest('.uat-checklist__summary');

		if (summary && !summary.parentElement.open) {
			window.requestAnimationFrame(() => scrollToTop(summary));
		}
	});

	checklist.addEventListener(SAVED_EVENT, (event) => {
		const done = countDone(checklist);
		const requiredDone = countDone(checklist, true);
		// Absent when no step is required, and then the count and the meter track every step.
		const requiredCount = checklist.querySelector(
			'.uat-checklist__required-done'
		);

		checklist.querySelector('.uat-checklist__done').textContent =
			String(done);
		if (requiredCount) {
			requiredCount.textContent = String(requiredDone);
		}
		checklist.querySelector('.uat-checklist__meter').value = requiredCount
			? requiredDone
			: done;
		advance(checklist, event.target);
	});
}

/**
 * Closes a saved step and opens the next one that needs testing, across sections and wrapping round to the
 * top, moving focus to its summary. With every step tested, the saved step closes and keeps focus.
 *
 * @param {HTMLElement} checklist Checklist region.
 * @param {HTMLElement} step      Step that was saved.
 */
function advance(checklist, step) {
	const steps = [...checklist.querySelectorAll('.uat-checklist__step')];
	const index = steps.indexOf(step);
	const current = step.querySelector('.uat-checklist__details');
	const next = [...steps.slice(index + 1), ...steps.slice(0, index)]
		.find(needsTesting)
		?.querySelector('.uat-checklist__details');

	current.open = false;

	if (!next) {
		current.querySelector('.uat-checklist__summary').focus();
		return;
	}

	next.open = true;

	const summary = next.querySelector('.uat-checklist__summary');
	summary.focus({ preventScroll: true });
	scrollToTop(summary);
}

/**
 * Scrolls a step's summary to the top of the panel, under its sticky bar, or of the page, under the admin bar.
 *
 * @param {HTMLElement} summary Summary of the step that opened.
 */
export function scrollToTop(summary) {
	const panel = summary.closest('.uat-panel');
	const cover = panel
		? panel.querySelector('.uat-panel__bar')
		: document.getElementById('wpadminbar');
	// The admin bar scrolls away on a phone, and then covers nothing.
	const top = Math.max(0, cover?.getBoundingClientRect().bottom ?? 0);

	(panel ?? document.scrollingElement).scrollTop +=
		summary.getBoundingClientRect().top - top;
}
