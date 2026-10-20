/*
 * Saving a step's result through the REST API, and the busy state and status line every checklist form shares.
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { SAVED_EVENT } from './checklist';

const FORM = '.uat-checklist__result';
const ROUTE = '/uat-checklist/v1/results';

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
 * @param {Object}          data    Request body.
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
		const response = await apiFetch({ path, method: 'POST', data });

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

	const response = await post(
		form,
		ROUTE,
		{
			checklist_id: Number(form.dataset.checklistId),
			step_id: form.dataset.stepId,
			status: result,
			comment,
		},
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
	// A save is a new result, so the retest notice is stale and the form starts blank, as after a reload.
	step.querySelector('.uat-checklist__retest')?.remove();
	form.reset();
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
		form.addEventListener('submit', (event) => {
			event.preventDefault();
			save(form);
		});
	});
}
