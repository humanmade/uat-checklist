/*
 * The report dialog's screenshot picker: checking the image chosen, previewing it above the upload button, and
 * freeing it again. The step form's picker (src/lib/step-form.js) does the same on server-rendered markup.
 */

import { __ } from '@wordpress/i18n';

/**
 * Returns the message for a file that is not an image the server takes.
 *
 * @return {string} Message.
 */
function typeProblem() {
	return __('Choose a JPEG, PNG, GIF or WebP image.', 'uat-checklist');
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
		return typeProblem();
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
 * Wires a file field to its preview, upload button and Remove control.
 *
 * Only a file the server would take is ever previewed or left in the field, so the field is what gets sent.
 *
 * @param {Object}            options           Picker elements and limits.
 * @param {HTMLInputElement}  options.input     File field.
 * @param {HTMLElement}       options.preview   Hidden holder of the preview image and the Remove control.
 * @param {HTMLElement}       options.upload    The upload button, which the field's label draws.
 * @param {HTMLButtonElement} options.remove    Remove control inside the preview.
 * @param {number}            options.maxBytes  Largest size the server accepts, in bytes.
 * @param {Function}          options.onProblem Called with the message when a chosen file is refused.
 * @return {Function} clear(): empties the field and frees the preview.
 */
export default function bindScreenshotPicker({
	input,
	preview,
	upload,
	remove,
	maxBytes,
	onProblem,
}) {
	const clearPreview = () => {
		const image = preview.querySelector('img');

		if (image) {
			window.URL.revokeObjectURL(image.src);
			image.remove();
		}

		preview.hidden = true;
		upload.textContent = __('Add a screenshot', 'uat-checklist');
	};

	const clear = () => {
		input.value = '';
		input.removeAttribute('aria-invalid');
		clearPreview();
	};

	const refuse = (message) => {
		clear();
		input.setAttribute('aria-invalid', 'true');
		onProblem(message);
	};

	input.addEventListener('change', () => {
		const file = input.files[0];

		input.removeAttribute('aria-invalid');

		if (!file) {
			clearPreview();
			return;
		}

		const problem = screenshotProblem(input, file, maxBytes);

		if (problem) {
			refuse(problem);
			return;
		}

		const image = document.createElement('img');

		clearPreview();
		image.src = window.URL.createObjectURL(file);
		image.alt = __('The screenshot you chose', 'uat-checklist');
		// The type checked so far comes from the file's name; an image the browser cannot draw is not one.
		image.addEventListener('error', () => refuse(typeProblem()));
		preview.prepend(image);
		preview.hidden = false;
		upload.textContent = __('Replace screenshot', 'uat-checklist');
	});

	remove.addEventListener('click', () => {
		clear();
		input.focus();
	});

	return clear;
}
