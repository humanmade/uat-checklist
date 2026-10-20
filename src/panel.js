/*
 * The Testing checklist panel, the same on the front end and in wp-admin. The admin bar item opens it, or a
 * floating button when the admin bar is not on screen. A picker lists every published checklist and the testing
 * progress on each. It opens with the checklist inc/panel.php names for this screen, the one whose instructions
 * page this is or the one the pop-out was opened for, and elsewhere with the one the tester last had open.
 */

import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import createPanel from './lib/panel';
import {
	bindChecklist,
	countDone,
	POPUP_WIDTH,
	SAVED_EVENT,
	WINDOW_CLASS,
	WINDOW_HASH,
} from './lib/checklist';
import bindNoteForms from './lib/note-form';
import {
	fetchRetests,
	openStep,
	renderRetests,
	renderRetestsError,
} from './lib/retests';
import bindStepForms from './lib/step-form';
import { announceWindow, watchWindow } from './lib/window-presence';
import './panel.scss';

const ROUTE = '/uat-checklist/v1/checklists';
const OPEN_KEY = 'uat-checklist-open:';
// localStorage key for the checklist the tester last had open, the panel's starting choice.
const LAST_KEY = 'uat-checklist:last-checklist';
// The checklist inc/panel.php names for this screen, or '' when none belongs to it.
const CURRENT = String(window.hmUatChecklist?.current || '');
// localStorage key set once a tester has seen the panel open, so it opens by itself only on their first visit.
const SEEN_KEY = 'uat-checklist:seen';
const ADMIN_BAR_ITEM = '#wp-admin-bar-uat-checklist > .ab-item';
// How long the pop-out waits after the last resize before putting its width back, so it does not fight a drag.
const RESIZE_SETTLE_MS = 150;

/**
 * Builds the panel shell: a picker and close button, the slot for the Ready to retest section, and the area the
 * checklist loads into.
 *
 * @return {{panel: HTMLElement, select: HTMLSelectElement, close: HTMLButtonElement, retests: HTMLElement, body: HTMLElement}} Shell.
 */
function buildShell() {
	const panel = document.createElement('div');
	panel.id = 'uat-panel';
	panel.className = 'uat-panel';
	panel.setAttribute('role', 'region');
	panel.setAttribute('aria-label', __('Testing checklist', 'uat-checklist'));
	panel.hidden = true;

	const bar = document.createElement('div');
	bar.className = 'uat-panel__bar';

	const label = document.createElement('label');
	label.htmlFor = 'uat-panel-select';
	label.textContent = __('Checklist', 'uat-checklist');

	const select = document.createElement('select');
	select.id = 'uat-panel-select';
	select.append(placeholderOption(__('Loading…', 'uat-checklist')));

	const close = document.createElement('button');
	close.type = 'button';
	close.className = 'uat-panel__close';
	close.textContent = __('Close', 'uat-checklist');

	const retests = document.createElement('div');

	const body = document.createElement('div');
	body.className = 'uat-panel__body';

	bar.append(label, select, close);
	panel.append(bar, retests, body);

	return { panel, select, close, retests, body };
}

/**
 * Returns a picker option that stands in for the list while it loads or when it could not load.
 *
 * Disabled so it can never be chosen, which would fire a change for no checklist.
 *
 * @param {string} text Option text.
 * @return {HTMLOptionElement} Option.
 */
function placeholderOption(text) {
	const option = new window.Option(text, '', true, true);
	option.disabled = true;

	return option;
}

/**
 * Builds the floating button shown only when the admin bar item is not on screen.
 *
 * @return {HTMLButtonElement} Button.
 */
function buildFloatingToggle() {
	const toggle = document.createElement('button');
	toggle.type = 'button';
	toggle.className = 'uat-panel-toggle';
	toggle.textContent = __('Testing checklist', 'uat-checklist');

	return toggle;
}

/**
 * Replaces the panel body with a line of text.
 *
 * @param {HTMLElement} body    Panel body.
 * @param {string}      message Text to show.
 */
function showMessage(body, message) {
	const paragraph = document.createElement('p');
	paragraph.className = 'uat-panel__message';
	paragraph.textContent = message;
	body.replaceChildren(paragraph);
}

/**
 * Returns a picker option's text: the checklist's name and its testing progress, counting the required steps
 * when it has any.
 *
 * @param {{title: string, done: number, total: number, required_done: number, required_total: number}} page Checklist page.
 * @return {string} Option text.
 */
function optionLabel(page) {
	if (page.required_total > 0) {
		return sprintf(
			/* translators: 1: checklist name, 2: required steps with a result, 3: required steps. */
			__('%1$s (%2$d / %3$d required)', 'uat-checklist'),
			page.title,
			page.required_done,
			page.required_total
		);
	}

	return sprintf(
		/* translators: 1: checklist name, 2: steps with a result, 3: steps. */
		__('%1$s (%2$d / %3$d)', 'uat-checklist'),
		page.title,
		page.done,
		page.total
	);
}

/**
 * Returns how far below the admin bar's top edge its lowest item reaches, counting items wrapped onto a second row.
 *
 * @param {HTMLElement}   bar   The admin bar.
 * @param {HTMLElement[]} items Its top-level items.
 * @return {number} Depth in pixels.
 */
function adminBarDepth(bar, items) {
	const top = bar.getBoundingClientRect().top;
	const shown = [bar, ...items].filter(
		(el) => el.getClientRects().length > 0
	);

	return Math.ceil(
		Math.max(...shown.map((el) => el.getBoundingClientRect().bottom - top))
	);
}

function init() {
	const adminBarItem = document.querySelector(ADMIN_BAR_ITEM);
	const isWindow = document.body.classList.contains(WINDOW_CLASS);
	const { panel, select, close, retests, body } = buildShell();
	const floatingToggle = buildFloatingToggle();
	const pages = new Map();
	let listLoaded = false;
	let setOpen = () => {};

	adminBarItem?.setAttribute('role', 'button');

	if (isWindow) {
		// The always-on-top window holds this page in a frame.
		announceWindow(window.top === window ? 'window' : 'on-top');
	} else if (adminBarItem) {
		// The admin bar item shows the checklist is open in a window, to sighted testers and in its accessible name.
		const inWindowNote = document.createElement('span');
		inWindowNote.className = 'screen-reader-text';
		adminBarItem.append(inWindowNote);

		watchWindow((kind) => {
			adminBarItem.parentElement.classList.toggle('is-in-window', !!kind);
			inWindowNote.textContent = kind
				? ` ${__('(open in a window)', 'uat-checklist')}`
				: '';
		});
	}
	document.body.append(panel, floatingToggle);

	// Two separate questions. The admin bar's own box decides the panel's top edge; the item's box decides the
	// floating button, since a site's own CSS can hide the item while the bar itself still shows.
	const adminBar = document.querySelector('#wpadminbar');
	const adminBarItems = [
		...(adminBar?.querySelectorAll('.ab-top-menu > li') ?? []),
	];
	const syncAdminBar = () => {
		const itemShown =
			!!adminBarItem && adminBarItem.getClientRects().length > 0;
		const barShown = !!adminBar && adminBar.getClientRects().length > 0;
		floatingToggle.hidden = itemShown;
		panel.classList.toggle('uat-panel--no-admin-bar', !barShown);

		if (barShown) {
			// Inline because only the rendered layout knows it: items that do not fit wrap onto a second row
			// below the bar's fixed-height box.
			panel.style.setProperty(
				'--uat-admin-bar-depth',
				`${adminBarDepth(adminBar, adminBarItems)}px`
			);
		}
	};
	syncAdminBar();
	new window.MutationObserver(syncAdminBar).observe(document.body, {
		attributes: true,
		attributeFilter: ['class'],
	});
	window.addEventListener('resize', syncAdminBar);

	// An item changing width, as a web font loads or Query Monitor updates its figures, can wrap the bar
	// without the window resizing.
	const itemObserver = new window.ResizeObserver(syncAdminBar);
	adminBarItems.forEach((item) => itemObserver.observe(item));

	const wire = (checklist) => {
		bindChecklist(checklist, { onPopOut: () => setOpen(false) });
		bindStepForms(checklist);
		bindNoteForms(checklist);
	};

	// Numbers each load, so a checklist that answers after the tester has picked another is dropped, not shown.
	let latestLoad = 0;

	const showChecklist = async (id) => {
		const load = ++latestLoad;
		window.localStorage.setItem(LAST_KEY, String(id));
		showMessage(body, __('Loading checklist…', 'uat-checklist'));

		try {
			const checklist = await apiFetch({ path: `${ROUTE}/${id}` });

			if (load !== latestLoad) {
				return;
			}

			// Markup rendered and escaped by the plugin's own checklist block, fetched same-origin.
			body.innerHTML = checklist.html;
			wire(body.querySelector('.uat-checklist'));
		} catch (error) {
			if (load !== latestLoad) {
				return;
			}

			showMessage(
				body,
				error?.message ??
					__(
						'The checklist could not be loaded. Check your connection and try again.',
						'uat-checklist'
					)
			);
		}
	};

	// Numbers each fetch, so a list that answers after a newer one is dropped.
	let latestRetests = 0;

	const openRetest = async (item) => {
		select.value = String(item.checklist_id);
		await showChecklist(select.value);
		openStep(body, item.step_id);
	};

	const loadRetests = async () => {
		const load = ++latestRetests;

		try {
			const items = await fetchRetests();

			if (load === latestRetests) {
				renderRetests(retests, items, openRetest);
			}
		} catch {
			if (load === latestRetests) {
				renderRetestsError(retests);
			}
		}
	};

	const loadList = async () => {
		if (listLoaded) {
			return;
		}

		listLoaded = true;
		showMessage(body, __('Loading checklists…', 'uat-checklist'));
		// Not awaited: the checklist does not wait on the list.
		loadRetests();

		try {
			const list = await apiFetch({ path: ROUTE });

			if (list.length === 0) {
				select.replaceChildren(
					placeholderOption(__('None yet', 'uat-checklist'))
				);
				showMessage(
					body,
					__(
						'No testing checklists yet. Add one under UAT → Checklists in wp-admin.',
						'uat-checklist'
					)
				);
				return;
			}

			list.forEach((page) => pages.set(String(page.id), page));
			select.replaceChildren(
				...list.map(
					(page) => new window.Option(optionLabel(page), page.id)
				)
			);

			const last = window.localStorage.getItem(LAST_KEY);
			select.value =
				[CURRENT, last].find((id) => id && pages.has(id)) ??
				String(list[0].id);

			await showChecklist(select.value);
		} catch (error) {
			listLoaded = false;
			select.replaceChildren(
				placeholderOption(__('Checklists not loaded', 'uat-checklist'))
			);
			showMessage(
				body,
				error?.message ??
					__(
						'The checklists could not be loaded. Check your connection and try again.',
						'uat-checklist'
					)
			);
		}
	};

	select.addEventListener('change', () => showChecklist(select.value));

	// Keeps the picker's progress in step with saves in the checklist shown.
	body.addEventListener(SAVED_EVENT, (event) => {
		const page = pages.get(select.value);

		if (!page) {
			return;
		}

		const checklist = event.target.closest('.uat-checklist');
		page.done = countDone(checklist);
		page.required_done = countDone(checklist, true);
		select.selectedOptions[0].textContent = optionLabel(page);
	});

	// A retested step drops off the Ready to retest list.
	body.addEventListener(SAVED_EVENT, loadRetests);

	// A tester who has never seen the panel finds it open on a checklist's instructions page, so they learn what
	// the UAT item does.
	const storageKey =
		OPEN_KEY + (isWindow ? `window:${window.location.pathname}` : 'main');
	const firstVisit =
		!!CURRENT &&
		!window.localStorage.getItem(SEEN_KEY) &&
		window.sessionStorage.getItem(storageKey) === null;

	setOpen = createPanel({
		panel,
		toggles: [adminBarItem, floatingToggle].filter(Boolean),
		// Core's admin bar sets aria-expanded on an item with a dropdown while the dropdown shows.
		pressed: [adminBarItem].filter(Boolean),
		closeButton: close,
		focusTarget: select,
		// The pop-out keeps its own key: a new window inherits its opener's sessionStorage.
		storageKey,
		openByDefault: isWindow || firstVisit,
		inWindow: isWindow,
		onOpen: loadList,
		// The admin bar item shows the panel is open with a class: its aria-expanded belongs to core's dropdown.
		onToggle: (open) => {
			adminBarItem?.parentElement.classList.toggle('is-panel-open', open);
			if (open) {
				window.localStorage.setItem(SEEN_KEY, '1');
			}
		},
	});

	// Opening by itself records nothing, so the open state is written here: the next UAT page in this tab keeps
	// the panel open until the tester closes it.
	if (firstVisit) {
		window.sessionStorage.setItem(storageKey, '1');
	}

	if (isWindow) {
		const openOnArrival = () => {
			if (window.location.hash !== `#${WINDOW_HASH}`) {
				return;
			}

			setOpen(true);
			// Dropped so a reload goes back to whatever the tester last chose in this window.
			window.history.replaceState(
				null,
				'',
				window.location.pathname + window.location.search
			);
		};

		openOnArrival();
		// A reused pop-out is navigated to the same address plus the fragment, which fires hashchange, not load.
		window.addEventListener('hashchange', openOnArrival);

		// Browsers ignore a request for a window that cannot be resized, so the pop-out puts its width back after
		// a resize. resizeBy() acts only on a window opened by window.open(), which the pop-out always is.
		let settle;
		window.addEventListener('resize', () => {
			window.clearTimeout(settle);
			settle = window.setTimeout(() => {
				const change = POPUP_WIDTH - window.innerWidth;

				if (change !== 0) {
					window.resizeBy(change, 0);
				}
			}, RESIZE_SETTLE_MS);
		});
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}
