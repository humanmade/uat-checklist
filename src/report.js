/*
 * Report an issue: a button at the top right of every screen, and the modal it opens. A tester describes a problem,
 * attaches a screenshot and sends it without leaving the page; the site records who they are and where they were.
 * While the panel is open the button is hidden by src/report.scss and the panel's footer opens the same dialog,
 * through the event src/lib/report-open.js names. inc/panel.php passes the page, the user and the limits, and leaves
 * the button out where it would cover an editor's toolbar.
 */

import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import {
	bindReportOpener,
	REPORT_OPEN_EVENT,
	REPORT_OPENER_ATTRIBUTE,
} from './lib/report-open';
import bindScreenshotPicker from './lib/report-screenshot';
import './report.scss';

const ROUTE = '/uat-checklist/v1/reports';
// Mirrors Screenshots\FIELD in inc/screenshots.php.
const SCREENSHOT_FIELD = 'screenshot';
const ACCEPT = 'image/png,image/jpeg,image/webp,image/gif';
const DATA = window.hmUatChecklist?.report;

/**
 * Returns a new element with a class and, optionally, text.
 *
 * @param {string} tag       Tag name.
 * @param {string} className Class name.
 * @param {string} [text]    Text content.
 * @return {HTMLElement} Element.
 */
function create(tag, className, text) {
	const element = document.createElement(tag);

	element.className = className;

	if (text !== undefined) {
		element.textContent = text;
	}

	return element;
}

/**
 * Returns a button.
 *
 * @param {string} className Class name.
 * @param {string} text      Label.
 * @param {string} [type]    Button type.
 * @return {HTMLButtonElement} Button.
 */
function createButton(className, text, type = 'button') {
	const button = create('button', className, text);

	button.type = type;

	return button;
}

/**
 * Builds the dialog: a title and Close, then the fields, then the status line and Submit.
 *
 * @return {Object} The dialog and the elements the script reads and writes.
 */
function buildDialog() {
	const dialog = create('dialog', 'uat-report');
	dialog.setAttribute('aria-labelledby', 'uat-report-title');

	const header = create('div', 'uat-report__header');
	const title = create(
		'h2',
		'uat-report__title',
		__('Report an issue', 'uat-checklist')
	);
	title.id = 'uat-report-title';
	const close = createButton(
		'uat-report__close',
		__('Close', 'uat-checklist')
	);
	header.append(title, close);

	const form = create('form', 'uat-report__form');
	form.noValidate = true;

	const body = create('div', 'uat-report__body');

	const descriptionLabel = create(
		'label',
		'uat-report__label',
		__('What went wrong?', 'uat-checklist')
	);
	descriptionLabel.htmlFor = 'uat-report-description';
	const description = create('textarea', 'uat-report__description');
	description.id = 'uat-report-description';
	description.name = 'description';
	description.rows = 5;
	description.maxLength = DATA.maxLength;
	description.required = true;

	const context = create('dl', 'uat-report__context');
	const pageTerm = create(
		'dt',
		'uat-report__term',
		__('Page', 'uat-checklist')
	);
	const page = create('dd', 'uat-report__detail');
	const pageTitle = create('span', 'uat-report__page-title');
	const pageUrl = create('span', 'uat-report__page-url');
	page.append(pageTitle, pageUrl);
	const userTerm = create(
		'dt',
		'uat-report__term',
		__('Signed in as', 'uat-checklist')
	);
	const user = create('dd', 'uat-report__detail', DATA.user);
	context.append(pageTerm, page, userTerm, user);

	const attach = create('div', 'uat-report__attach');
	const attachLabel = create(
		'span',
		'uat-report__label',
		__('Screenshot', 'uat-checklist')
	);
	const preview = create('div', 'uat-report__preview');
	preview.hidden = true;
	const remove = createButton(
		'uat-report__remove',
		__('Remove screenshot', 'uat-checklist')
	);
	preview.append(remove);
	const file = create('input', 'uat-report__file');
	file.type = 'file';
	file.id = 'uat-report-file';
	file.name = SCREENSHOT_FIELD;
	file.accept = ACCEPT;
	file.setAttribute('aria-describedby', 'uat-report-file-hint');
	const upload = create(
		'label',
		'uat-report__upload',
		__('Add a screenshot', 'uat-checklist')
	);
	upload.htmlFor = file.id;
	const hint = create(
		'p',
		'uat-report__hint',
		sprintf(
			/* translators: %s: the largest file size the site accepts, such as 2 MB. */
			__(
				'Optional. A JPEG, PNG, GIF or WebP image of up to %s.',
				'uat-checklist'
			),
			DATA.maxSize
		)
	);
	hint.id = 'uat-report-file-hint';
	attach.append(attachLabel, preview, file, upload, hint);

	body.append(descriptionLabel, description, context, attach);

	const actions = create('div', 'uat-report__actions');
	const status = create('p', 'uat-report__status');
	status.setAttribute('role', 'status');
	const submit = createButton(
		'uat-report__submit',
		__('Submit report', 'uat-checklist'),
		'submit'
	);
	actions.append(submit, status);

	form.append(body, actions);
	dialog.append(header, form);

	return {
		dialog,
		close,
		form,
		body,
		description,
		pageTitle,
		pageUrl,
		file,
		preview,
		upload,
		remove,
		status,
		submit,
	};
}

/**
 * Returns the page the report records: its address without the query string or hash, which can carry a token, and its
 * title cut to the length the server keeps.
 *
 * @return {{url: string, title: string}} The address and title.
 */
function currentPage() {
	const { origin, pathname } = window.location;

	return {
		url: origin + pathname,
		// Characters, not UTF-16 units, as the server counts them.
		title: Array.from(document.title).slice(0, DATA.maxTitle).join(''),
	};
}

/**
 * Returns the first control that opens the dialog and is on screen, starting with the one that did.
 *
 * The panel can close while the dialog is open, taking its footer button with it.
 *
 * @param {?HTMLElement} opener The control the tester used.
 * @return {?HTMLElement} Control to focus.
 */
function visibleOpener(opener) {
	return [
		opener,
		...document.querySelectorAll(`[${REPORT_OPENER_ATTRIBUTE}]`),
	].find((control) => control?.getClientRects().length > 0);
}

/**
 * Adds the dialog, and the top-right button unless inc/panel.php left it out, and wires them to the report form.
 */
function init() {
	if (!DATA) {
		return;
	}

	const ui = buildDialog();
	const { dialog, description, status, submit, close } = ui;
	let busy = false;
	let opener = null;
	// What the dialog showed on opening, which is what the report records.
	let page = { url: '', title: '' };

	document.body.append(dialog);

	if (DATA.toggle) {
		const toggle = createButton(
			'uat-report-toggle',
			__('Report an issue', 'uat-checklist')
		);

		bindReportOpener(toggle);
		document.body.append(toggle);
	}

	// A tick after the region is on screen, as some screen readers miss text set in the same task.
	const say = (message, isError = false) =>
		window.setTimeout(() => {
			status.textContent = message;
			status.classList.toggle('is-error', isError);
		}, 0);

	const setBusy = (isBusy) => {
		busy = isBusy;

		// aria-disabled rather than disabled: a disabled button drops keyboard focus to the page body.
		[submit, close].forEach((button) =>
			isBusy
				? button.setAttribute('aria-disabled', 'true')
				: button.removeAttribute('aria-disabled')
		);
	};

	// Listens before the picker, so a refusal it reports is not wiped, and a good choice clears an earlier one.
	ui.file.addEventListener('change', () => say(''));

	const clearScreenshot = bindScreenshotPicker({
		input: ui.file,
		preview: ui.preview,
		upload: ui.upload,
		remove: ui.remove,
		maxBytes: DATA.maxBytes,
		onProblem: (message) => say(message, true),
	});

	const open = (from) => {
		if (dialog.open) {
			return;
		}

		opener = from;
		page = currentPage();
		ui.pageTitle.textContent = page.title;
		ui.pageUrl.textContent = page.url;
		dialog.showModal();
		description.focus();
	};

	document.addEventListener(REPORT_OPEN_EVENT, (event) =>
		open(event.detail?.opener)
	);

	// A request in flight cannot be taken back, so the dialog stays until it answers.
	dialog.addEventListener('cancel', (event) => {
		if (busy) {
			event.preventDefault();
		}
	});

	close.addEventListener('click', () => {
		if (!busy) {
			dialog.close();
		}
	});

	// The description is kept for the next opening, so a stray Esc does not lose it. A screenshot is not, which
	// frees its image.
	dialog.addEventListener('close', () => {
		clearScreenshot();
		ui.body.hidden = false;
		submit.hidden = false;
		description.removeAttribute('aria-invalid');
		say('');
		visibleOpener(opener)?.focus();
	});

	description.addEventListener('input', () =>
		description.removeAttribute('aria-invalid')
	);

	ui.form.addEventListener('submit', async (event) => {
		event.preventDefault();

		if (busy) {
			return;
		}

		const text = description.value.trim();

		if (text === '') {
			say(__('Describe the problem first.', 'uat-checklist'), true);
			description.setAttribute('aria-invalid', 'true');
			description.focus();
			return;
		}

		// Already checked when it was chosen, by the picker.
		const screenshot = ui.file.files[0];
		const body = new window.FormData();
		body.append('description', text);
		body.append('page_url', page.url);
		body.append('page_id', String(DATA.page.id));
		body.append('page_title', page.title);

		if (screenshot) {
			body.append(SCREENSHOT_FIELD, screenshot);
		}

		setBusy(true);
		say(
			screenshot
				? __('Uploading the screenshot…', 'uat-checklist')
				: __('Sending the report…', 'uat-checklist')
		);

		try {
			await apiFetch({ path: ROUTE, method: 'POST', body });

			description.value = '';
			clearScreenshot();
			ui.body.hidden = true;
			submit.hidden = true;
			say(__('Thank you. Your report was sent.', 'uat-checklist'));
			// The focused Submit button has just left the page.
			close.focus();
		} catch (error) {
			say(
				error?.message ??
					__(
						'The report could not be sent. Check your connection and try again.',
						'uat-checklist'
					),
				true
			);
		} finally {
			setBusy(false);
		}
	});
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}
