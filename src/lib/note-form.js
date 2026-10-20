/*
 * Saving a tester's additional comments on a checklist through the REST API.
 */

import { __ } from '@wordpress/i18n';

import { isSaving, post } from './step-form';

const FORM = '.uat-checklist__notes-form';
const ROUTE = '/uat-checklist/v1/notes';

/**
 * Posts the form and reports the outcome in its status line. An empty comment deletes the tester's saved one.
 *
 * @param {HTMLFormElement} form Additional comments form.
 */
function save(form) {
	if (isSaving(form)) {
		return;
	}

	post(
		form,
		ROUTE,
		{
			checklist_id: Number(form.dataset.checklistId),
			comment: form.elements.comment.value.trim(),
		},
		__(
			'The comment could not be saved. Check your connection and try again.',
			'uat-checklist'
		)
	);
}

/**
 * Shows and wires the additional comments form under `root`.
 *
 * @param {HTMLElement} root Checklist holding the form.
 */
export default function bindNoteForms(root) {
	root.querySelectorAll(FORM).forEach((form) => {
		form.hidden = false;
		form.addEventListener('submit', (event) => {
			event.preventDefault();
			save(form);
		});
	});
}
