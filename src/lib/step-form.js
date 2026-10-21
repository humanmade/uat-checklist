/*
 * Saving a step's result through the REST API, and the busy state and status line every checklist form shares.
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { SAVED_EVENT } from './checklist';

const FORM = '.uat-checklist__result';
const ROUTE = '/uat-checklist/v1/results';
// Mirrors Screenshots\FIELD in inc/screenshots.php.
const SCREENSHOT_FIELD = 'screenshot';
// Names the result whose screenshot the preview shows; blocks/step/render.php prints it.
const FROM_FIELD = 'screenshot_from';
const PREVIEW = '.uat-checklist__preview';
const UPLOAD = '.uat-checklist__upload';

/**
 * Shows a message in the form's status line, styled as an error when `isError` is set.
 *
 * @param {HTMLElement} status  Status line.
 * @param {string}      message Text to show.
 * @param {boolean}     isError Whether the message reports a problem.
 */
function report(status, message, isError = false) {
	status.textContent = message;
	status.classList.toggle('is-error', isError);
}

/**
 * Returns whether the form's last save is still in flight.
 *
 * @param {HTMLFormElement} form Form with a Save button.
 * @return {boolean} Whether a save is in progress.
 */
export function isSaving(form) {
	return (
		form
			.querySelector('.uat-checklist__save')
			.getAttribute('aria-disabled') === 'true'
	);
}

/**
 * Posts a form's data, with its Save button marked busy until it answers and the outcome in its status line.
 *
 * @param {HTMLFormElement} form    Form being saved.
 * @param {string}          path    REST route.
 * @param {Object|FormData} data    Request body: an object is sent as JSON, FormData as it is, for a file.
 * @param {string}          failure Message shown when the request fails without one of its own.
 * @return {Promise<?Object>} The response, or null when the request failed.
 */
export async function post(form, path, data, failure) {
	const button = form.querySelector('.uat-checklist__save');
	const status = form.querySelector('.uat-checklist__status');

	// aria-disabled rather than disabled: a disabled button drops keyboard focus to the page body.
	button.setAttribute('aria-disabled', 'true');
	report(status, __('Saving…', 'uat-checklist'));

	try {
		const response = await apiFetch({
			path,
			method: 'POST',
			...(data instanceof window.FormData ? { body: data } : { data }),
		});

		report(status, response.message);
		return response;
	} catch (error) {
		report(status, error?.message ?? failure, true);
		return null;
	} finally {
		button.removeAttribute('aria-disabled');
	}
}

/**
 * Marks the comment as required while Fail is chosen, and clears an error once the tester acts on it.
 *
 * @param {HTMLFormElement} form Step result form.
 */
function syncRequired(form) {
	const failing = form.elements.result.value === 'fail';
	const comment = form.elements.comment;

	comment.setAttribute('aria-required', String(failing));

	if (!failing || comment.value.trim() !== '') {
		comment.removeAttribute('aria-invalid');
	}
}

/**
 * Returns why a chosen screenshot cannot be sent, or '' when it can. The server checks the same limits; this saves
 * uploading a file it would refuse.
 *
 * @param {HTMLInputElement} input File field, whose `accept` lists the image types the server takes.
 * @param {File}             file  Chosen file.
 * @param {number}           max   Largest size the server accepts, in bytes.
 * @return {string} The problem, or ''.
 */
function screenshotProblem(input, file, max) {
	if (!input.accept.split(',').includes(file.type)) {
		return __('Choose a JPEG, PNG, GIF or WebP image.', 'uat-checklist');
	}

	if (file.size > max) {
		return __(
			'The screenshot is too large. Choose a smaller image.',
			'uat-checklist'
		);
	}

	return '';
}

/**
 * Removes the screenshot preview, frees its image, and sets the upload button back to adding one.
 *
 * The saved screenshot goes with it, so the next save does not carry it over.
 *
 * @param {HTMLFormElement} form Step result form.
 */
function clearPreview(form) {
	const preview = form.querySelector(PREVIEW);
	const image = preview.querySelector('img');

	if (image) {
		window.URL.revokeObjectURL(image.src);
		image.remove();
	}

	form.elements[FROM_FIELD].value = '';
	preview.hidden = true;
	form.querySelector(UPLOAD).textContent = __(
		'Add a screenshot',
		'uat-checklist'
	);
}

/**
 * Reports why a chosen screenshot cannot be sent, and clears it and its preview.
 *
 * @param {HTMLFormElement} form    Step result form.
 * @param {string}          message The problem.
 */
function refuse(form, message) {
	const input = form.elements[SCREENSHOT_FIELD];

	report(form.querySelector('.uat-checklist__status'), message, true);
	input.setAttribute('aria-invalid', 'true');
	input.value = '';
	clearPreview(form);
}

/**
 * Shows a screenshot full width above the upload button, which then offers to replace it.
 *
 * @param {HTMLFormElement} form Step result form.
 * @param {string}          src  Image address.
 * @param {string}          alt  Alternative text.
 * @return {HTMLImageElement} The image shown.
 */
function showPreview(form, src, alt) {
	const preview = form.querySelector(PREVIEW);
	const image = form.ownerDocument.createElement('img');

	clearPreview(form);
	image.src = src;
	image.alt = alt;
	preview.prepend(image);
	preview.hidden = false;
	form.querySelector(UPLOAD).textContent = __(
		'Replace screenshot',
		'uat-checklist'
	);

	return image;
}

/**
 * Previews the file just chosen, or reports why it cannot be sent and clears it, so only a file the server would
 * take is ever previewed or sent.
 *
 * @param {HTMLFormElement} form Step result form.
 */
function choose(form) {
	const input = form.elements[SCREENSHOT_FIELD];
	const file = input.files[0];

	input.removeAttribute('aria-invalid');

	if (!file) {
		clearPreview(form);
		return;
	}

	const problem = screenshotProblem(
		input,
		file,
		Number(form.dataset.maxUpload)
	);

	if (problem) {
		refuse(form, problem);
		return;
	}

	const image = showPreview(
		form,
		window.URL.createObjectURL(file),
		__('The screenshot you chose', 'uat-checklist')
	);

	// The type checked so far comes from the file's name; an image the browser cannot draw is not one.
	image.addEventListener('error', () =>
		refuse(
			form,
			__('Choose a JPEG, PNG, GIF or WebP image.', 'uat-checklist')
		)
	);
}

/**
 * Validates the form, posts it, and reports the outcome in the form's status line.
 *
 * @param {HTMLFormElement} form Step result form.
 */
async function save(form) {
	if (isSaving(form)) {
		return;
	}

	const data = new window.FormData(form);
	const result = data.get('result');
	const status = form.querySelector('.uat-checklist__status');

	if (!result) {
		report(status, __('Choose Pass or Fail first.', 'uat-checklist'), true);
		form.elements.result[0].focus();
		return;
	}

	const comment = String(data.get('comment') ?? '').trim();

	if (result === 'fail' && comment === '') {
		report(
			status,
			__('Add a comment saying what went wrong.', 'uat-checklist'),
			true
		);
		form.elements.comment.setAttribute('aria-invalid', 'true');
		form.elements.comment.focus();
		return;
	}

	// Already checked when it was chosen, by choose().
	const file = form.elements[SCREENSHOT_FIELD].files[0];
	const from = form.elements[FROM_FIELD].value;
	const body = new window.FormData();
	body.append('checklist_id', form.dataset.checklistId);
	body.append('step_id', form.dataset.stepId);
	body.append('status', result);
	body.append('comment', comment);

	if (file) {
		body.append(SCREENSHOT_FIELD, file);
	} else if (from) {
		body.append(FROM_FIELD, from);
	}

	const response = await post(
		form,
		ROUTE,
		body,
		__(
			'The result could not be saved. Check your connection and try again.',
			'uat-checklist'
		)
	);

	if (!response) {
		return;
	}

	const step = form.closest('.uat-checklist__step');
	const badge = step.querySelector('.uat-checklist__badge');

	step.dataset.result = response.status;
	badge.textContent = response.label;
	badge.hidden = false;
	// A save is a new result, so the retest notice is stale. The form keeps what was saved, as after a reload, with
	// the file field emptied so the screenshot is carried over rather than sent again.
	step.querySelector('.uat-checklist__retest')?.remove();
	form.elements[SCREENSHOT_FIELD].value = '';
	form.elements[SCREENSHOT_FIELD].removeAttribute('aria-invalid');

	if (response.screenshot) {
		showPreview(form, response.screenshot.url, response.screenshot.alt);
		form.elements[FROM_FIELD].value = String(response.id);
	} else {
		clearPreview(form);
	}

	syncRequired(form);
	step.dispatchEvent(new window.CustomEvent(SAVED_EVENT, { bubbles: true }));
}

/**
 * Shows and wires every step result form under `root`.
 *
 * @param {HTMLElement} root Checklist holding the forms.
 */
export default function bindStepForms(root) {
	root.querySelectorAll(FORM).forEach((form) => {
		form.hidden = false;
		syncRequired(form);
		form.addEventListener('input', () => syncRequired(form));
		const input = form.elements[SCREENSHOT_FIELD];

		input.addEventListener('change', () => choose(form));
		form.querySelector('.uat-checklist__remove').addEventListener(
			'click',
			() => {
				input.value = '';
				clearPreview(form);
				input.focus();
			}
		);
		form.addEventListener('submit', (event) => {
			event.preventDefault();
			save(form);
		});
	});
}
