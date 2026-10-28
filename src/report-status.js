/*
 * Issue report status in wp-admin: moves a report to another status through the REST API, from a row action on the list,
 * from the list's bulk action and from the status box on the edit screen. Nothing posts to edit.php or post.php. A
 * report is a record that a triager only moves between statuses, and a save through core would make the saving
 * user its author. inc/report-admin.php passes the route, the bulk action's prefix and the status labels.
 */

import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

const DATA = window.hmUatReportAdmin;
// Mirrors the bulk route's limit on `ids` in inc/reports.php.
const BULK_LIMIT = 50;
const NOTICE_CLASS = 'uat-report-admin__notice';

/**
 * Returns the list's row for a report.
 *
 * @param {number|string} id Report ID.
 * @return {HTMLElement|null} Row, or null when the report is not on this screen.
 */
function findRow(id) {
	return document.getElementById(`post-${id}`);
}

/**
 * Returns what the list shows for a report: its title, or its ID when the row has none.
 *
 * @param {number|string} id Report ID.
 * @return {string} Name.
 */
function reportName(id) {
	const title = findRow(id)?.querySelector('.row-title')?.textContent;

	return title || `#${id}`;
}

/**
 * Removes the notice this script showed, if any.
 *
 * @return {void}
 */
function clearNotice() {
	document.querySelector(`.${NOTICE_CLASS}`)?.remove();
}

/**
 * Shows a dismissible notice under the screen's title and announces it to a screen reader.
 *
 * @param {string}   type     Notice type: `success`, `warning` or `error`.
 * @param {string[]} messages Paragraphs of the notice.
 * @return {void}
 */
function showNotice(type, messages) {
	clearNotice();

	const notice = document.createElement('div');
	notice.className = `notice notice-${type} is-dismissible ${NOTICE_CLASS}`;

	messages.forEach((message) => {
		const paragraph = document.createElement('p');
		paragraph.textContent = message;
		notice.append(paragraph);
	});

	const dismiss = document.createElement('button');
	dismiss.type = 'button';
	dismiss.className = 'notice-dismiss';

	const dismissText = document.createElement('span');
	dismissText.className = 'screen-reader-text';
	dismissText.textContent = __('Dismiss this notice.', 'uat-checklist');
	dismiss.append(dismissText);
	dismiss.addEventListener('click', () => notice.remove());
	notice.append(dismiss);

	// Core puts a screen's own notices after this, so the notice lands under the title as theirs do.
	const anchor =
		document.querySelector('.wrap .wp-header-end') ||
		document.querySelector('.wrap h1');

	anchor?.after(notice);

	speak(messages.join(' '), type === 'error' ? 'assertive' : 'polite');
}

/**
 * Returns the message of a failed request.
 *
 * @param {Error|{message?: string}} error What the request rejected with.
 * @return {string} Message.
 */
function errorMessage(error) {
	return (
		error?.message ||
		__('The status could not be changed.', 'uat-checklist')
	);
}

/**
 * Marks a button as busy, and says whether it already was.
 *
 * It is `aria-disabled` rather than `disabled` so a keyboard user's focus stays on it.
 *
 * @param {HTMLElement} button Button.
 * @param {boolean}     busy   Whether a request is in flight.
 * @return {void}
 */
function setBusy(button, busy) {
	if (busy) {
		button.setAttribute('aria-disabled', 'true');
	} else {
		button.removeAttribute('aria-disabled');
	}
}

/**
 * Fills a row's status actions with a button for each status the report is not in.
 *
 * @param {HTMLElement} container Element core wraps the status actions in.
 * @param {string}      id        Report ID.
 * @param {string}      current   The report's status.
 * @return {void}
 */
function renderMarkButtons(container, id, current) {
	container.replaceChildren();

	Object.keys(DATA.statuses)
		.filter((status) => status !== current)
		.forEach((status, index) => {
			if (index > 0) {
				container.append(' | ');
			}

			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'button-link uat-report-admin__mark';
			button.dataset.uatReportId = id;
			button.dataset.uatReportStatus = status;
			button.textContent = DATA.marks[status];
			container.append(button);
		});
}

/**
 * Shows a report's new status wherever this screen shows it: the list row's Status cell and actions, and the edit
 * screen's details and select.
 *
 * @param {number|string} id     Report ID.
 * @param {string}        status The report's new status.
 * @return {void}
 */
function showStatus(id, status) {
	const label = DATA.statuses[status];
	const row = findRow(id);

	if (row) {
		const cell = row.querySelector('.column-uat_status');
		const actions = row.querySelector('.row-actions .uat_report_status');

		if (cell) {
			cell.textContent = label;
		}

		if (actions) {
			renderMarkButtons(actions, String(id), status);
		}
	}

	const current = document.querySelector('.uat-report-admin__current');
	const select = document.getElementById('uat-report-status-select');

	if (current) {
		current.textContent = label;
	}

	if (select) {
		select.value = status;
	}
}

/**
 * Removes a report's row when the list is filtered to a status the report has just left.
 *
 * @param {number|string} id     Report ID.
 * @param {string}        status The report's new status.
 * @return {boolean} Whether the row was removed.
 */
function dropRowOutsideView(id, status) {
	const view = new URLSearchParams(window.location.search).get('post_status');
	const row = findRow(id);

	if (!row || !view || view === 'all' || view === status) {
		return false;
	}

	row.remove();

	return true;
}

// Counts the list refreshes, so only the latest response is shown.
let refreshes = 0;

/**
 * Replaces the list's status links and item counts, so they match the change, and refills the table when every row
 * on the page has gone.
 *
 * Read from the list's own address, which is what core counts from. A failed request leaves the old counts, and the
 * next load corrects them.
 *
 * @return {Promise<void>}
 */
async function refreshViews() {
	const views = document.querySelector('.subsubsub');

	if (!views) {
		return;
	}

	refreshes += 1;
	const request = refreshes;

	try {
		const response = await window.fetch(window.location.href, {
			credentials: 'same-origin',
		});
		const page = new window.DOMParser().parseFromString(
			await response.text(),
			'text/html'
		);

		// A later change started its own refresh, and an earlier response can land after it.
		if (request !== refreshes) {
			return;
		}

		const fresh = page.querySelector('.subsubsub');
		const pages = page.querySelectorAll('.tablenav-pages');
		const list = document.getElementById('the-list');
		const freshList = page.getElementById('the-list');

		if (fresh) {
			views.replaceChildren(...fresh.childNodes);
		}

		document
			.querySelectorAll('.tablenav-pages')
			.forEach((current, index) => {
				if (pages[index]) {
					current.replaceWith(pages[index]);
				}
			});

		if (list && freshList && !list.querySelector('tr[id^="post-"]')) {
			list.replaceChildren(...freshList.childNodes);
		}
	} catch {
		// The counts are a convenience: the report has already moved.
	}
}

/**
 * Moves one report to a status.
 *
 * @param {number|string} id     Report ID.
 * @param {string}        status One of the statuses in `DATA.statuses`.
 * @return {Promise<Object>} The report, as the route returns it.
 */
function changeStatus(id, status) {
	return apiFetch({
		path: `${DATA.route}/${id}/status`,
		method: 'POST',
		data: { status },
	});
}

/**
 * Handles a row action: moves the report and shows the result in its row.
 *
 * @param {HTMLButtonElement} button The row action that was used.
 * @return {Promise<void>}
 */
async function handleMark(button) {
	if (button.getAttribute('aria-disabled') === 'true') {
		return;
	}

	const { uatReportId: id, uatReportStatus: status } = button.dataset;

	clearNotice();
	setBusy(button, true);

	try {
		const report = await changeStatus(id, status);

		showStatus(id, report.status);

		// The button that was used is gone, and focus would fall to the page.
		if (dropRowOutsideView(id, report.status)) {
			document.querySelector('#the-list .row-title')?.focus();
		} else {
			findRow(id)?.querySelector('.uat-report-admin__mark')?.focus();
		}

		refreshViews();
		speak(
			sprintf(
				/* translators: %s: a report status, such as Investigating. */
				__('Report moved to %s.', 'uat-checklist'),
				DATA.statuses[report.status]
			)
		);
	} catch (error) {
		setBusy(button, false);
		showNotice('error', [errorMessage(error)]);
	}
}

/**
 * Handles the edit screen's Update status button.
 *
 * @param {HTMLButtonElement} button The Update status button.
 * @return {Promise<void>}
 */
async function handleUpdate(button) {
	if (button.getAttribute('aria-disabled') === 'true') {
		return;
	}

	const select = document.getElementById('uat-report-status-select');

	setBusy(button, true);

	try {
		const report = await changeStatus(
			button.dataset.uatReportId,
			select.value
		);

		showStatus(report.id, report.status);
		showNotice('success', [
			sprintf(
				/* translators: %s: a report status, such as Investigating. */
				__('Report moved to %s.', 'uat-checklist'),
				DATA.statuses[report.status]
			),
		]);
	} catch (error) {
		showNotice('error', [errorMessage(error)]);
	} finally {
		setBusy(button, false);
	}
}

/**
 * Returns the bulk action chosen in the list's dropdowns, whichever of the two is set.
 *
 * @param {HTMLFormElement} form The list's form.
 * @return {string} Action, or an empty string when none is chosen.
 */
function chosenBulkAction(form) {
	const top = form.querySelector('select[name="action"]')?.value;
	const bottom = form.querySelector('select[name="action2"]')?.value;

	if (top && top !== '-1') {
		return top;
	}

	return bottom && bottom !== '-1' ? bottom : '';
}

/**
 * Moves reports to a status in as many requests as the route's limit asks for.
 *
 * @param {string[]} ids    Report IDs.
 * @param {string}   status One of the statuses in `DATA.statuses`.
 * @return {Promise<{updated: number[], failed: Array<{id: number, message: string}>}>} Outcome of every request.
 */
async function changeStatuses(ids, status) {
	const outcome = { updated: [], failed: [] };

	for (let start = 0; start < ids.length; start += BULK_LIMIT) {
		// Requests run one after another, so the server is not asked for fifty writes at once.
		const result = await apiFetch({
			path: `${DATA.route}/status`,
			method: 'POST',
			data: { ids: ids.slice(start, start + BULK_LIMIT), status },
		});

		outcome.updated.push(...result.updated);
		outcome.failed.push(...result.failed);
	}

	return outcome;
}

/**
 * Describes a bulk change for the notice: how many reports moved, and which ones could not and why.
 *
 * @param {{updated: number[], failed: Array<{id: number, message: string}>}} outcome Result of the change.
 * @param {string}                                                            status  Status the reports were moved to.
 * @return {string[]} Paragraphs of the notice.
 */
function describeBulk(outcome, status) {
	const messages = [];

	if (outcome.updated.length > 0) {
		messages.push(
			sprintf(
				/* translators: 1: number of reports, 2: a report status, such as Investigating. */
				_n(
					'%1$d report moved to %2$s.',
					'%1$d reports moved to %2$s.',
					outcome.updated.length,
					'uat-checklist'
				),
				outcome.updated.length,
				DATA.statuses[status]
			)
		);
	}

	outcome.failed.forEach(({ id, message }) => {
		messages.push(
			sprintf(
				/* translators: 1: a report's title, 2: why it could not be moved. */
				__('%1$s could not be moved: %2$s', 'uat-checklist'),
				reportName(id),
				message
			)
		);
	});

	return messages;
}

/**
 * Handles the list's bulk action: moves the checked reports and shows the result in their rows.
 *
 * @param {HTMLFormElement} form   The list's form.
 * @param {string[]}        ids    Checked report IDs.
 * @param {string}          status Status the action moves them to.
 * @return {Promise<void>}
 */
async function handleBulk(form, ids, status) {
	const buttons = form.querySelectorAll('#doaction, #doaction2');

	clearNotice();
	buttons.forEach((button) => setBusy(button, true));

	try {
		const outcome = await changeStatuses(ids, status);

		outcome.updated.forEach((id) => {
			showStatus(id, status);
			const box = findRow(id)?.querySelector('input[name="post[]"]');

			if (box) {
				box.checked = false;
			}

			dropRowOutsideView(id, status);
		});

		refreshViews();

		form.querySelectorAll(
			'select[name="action"], select[name="action2"]'
		).forEach((select) => {
			select.value = '-1';
		});

		showNotice(
			outcome.failed.length > 0 ? 'warning' : 'success',
			describeBulk(outcome, status)
		);
	} catch (error) {
		showNotice('error', [errorMessage(error)]);
	} finally {
		buttons.forEach((button) => setBusy(button, false));
	}
}

/**
 * Sends the list's bulk action to the REST API instead of to edit.php, when it is one of ours and a row is checked.
 *
 * Any other submit goes to core as usual, and edit.php does nothing with an action of ours.
 *
 * @param {SubmitEvent} event Submit event of the list's form.
 * @return {void}
 */
function interceptBulk(event) {
	const form = event.currentTarget;
	const action = chosenBulkAction(form);
	const ids = [...form.querySelectorAll('input[name="post[]"]:checked')].map(
		(box) => box.value
	);

	if (!action.startsWith(DATA.bulkPrefix) || ids.length === 0) {
		return;
	}

	event.preventDefault();
	handleBulk(form, ids, action.slice(DATA.bulkPrefix.length));
}

/**
 * Wires the screen: the row actions and bulk form on the list, and the status box on the edit screen.
 *
 * @return {void}
 */
function init() {
	document.addEventListener('click', (event) => {
		const mark = event.target.closest('.uat-report-admin__mark');
		const update = event.target.closest('#uat-report-status-update');

		if (mark) {
			handleMark(mark);
		} else if (update) {
			handleUpdate(update);
		}
	});

	document
		.getElementById('posts-filter')
		?.addEventListener('submit', interceptBulk);
}

if (DATA) {
	init();
}
