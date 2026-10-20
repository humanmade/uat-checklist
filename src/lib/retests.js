/*
 * The panel's Ready to retest section: the current tester's Fails that a UAT manager has since marked fixed,
 * each a button that opens its step.
 */

import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import { scrollToTop } from './checklist';

const ROUTE = '/uat-checklist/v1/retests';

/**
 * Fetches the tester's steps that are ready to retest, ordered by checklist then step.
 *
 * @return {Promise<Array<{checklist_id: number, checklist_title: string, step_id: string, step: string, note: string, fixed_by: string, fixed_at: string, url: string}>>} Items.
 */
export function fetchRetests() {
	return apiFetch({ path: ROUTE });
}

/**
 * Returns the line saying who marked a step fixed, and when.
 *
 * @param {{fixed_by: string, fixed_at: string}} item Retest item.
 * @return {string} Text.
 */
function fixedLine(item) {
	if (item.fixed_by === '') {
		return sprintf(
			/* translators: %s: date and time the step was marked fixed. */
			__('Marked fixed on %s.', 'uat-checklist'),
			item.fixed_at
		);
	}

	return sprintf(
		/* translators: 1: name of the person who marked the step fixed, 2: date and time. */
		__('Marked fixed by %1$s on %2$s.', 'uat-checklist'),
		item.fixed_by,
		item.fixed_at
	);
}

/**
 * Returns a span of text, with a class naming its part of the item.
 *
 * @param {string} part Class suffix.
 * @param {string} text Text content.
 * @return {HTMLSpanElement} Span.
 */
function textSpan(part, text) {
	const span = document.createElement('span');
	span.className = `uat-panel__retest-${part}`;
	span.textContent = text;

	return span;
}

/**
 * Builds one list item: a button showing the step, its checklist, who marked it fixed and the note.
 *
 * @param {Object}   item     Retest item.
 * @param {Function} onSelect Called with the item when its button is clicked.
 * @return {HTMLLIElement} List item.
 */
function buildItem(item, onSelect) {
	const button = document.createElement('button');
	button.type = 'button';
	button.className = 'uat-panel__retest';
	button.append(
		textSpan('step', item.step),
		textSpan('checklist', item.checklist_title),
		textSpan('fixed', fixedLine(item))
	);

	if (item.note !== '') {
		button.append(textSpan('note', item.note));
	}

	button.addEventListener('click', () => onSelect(item));

	const listItem = document.createElement('li');
	listItem.append(button);

	return listItem;
}

/**
 * Fills the slot with the Ready to retest section, or with nothing when no step is ready.
 *
 * A section the tester collapsed stays collapsed when the list is refreshed after a save.
 *
 * @param {HTMLElement} slot     Element the section goes in.
 * @param {Array}       items    Retest items.
 * @param {Function}    onSelect Called with an item when the tester chooses it.
 */
export function renderRetests(slot, items, onSelect) {
	if (items.length === 0) {
		slot.replaceChildren();
		return;
	}

	const section = document.createElement('details');
	section.className = 'uat-panel__retests';
	section.open = slot.querySelector('details')?.open ?? true;

	const summary = document.createElement('summary');
	summary.textContent = sprintf(
		/* translators: %d: number of steps ready to retest. */
		__('Ready to retest (%d)', 'uat-checklist'),
		items.length
	);

	const list = document.createElement('ul');
	list.className = 'uat-panel__retest-list';
	list.append(...items.map((item) => buildItem(item, onSelect)));

	section.append(summary, list);
	slot.replaceChildren(section);
}

/**
 * Fills the slot with a message that the list could not be loaded.
 *
 * @param {HTMLElement} slot Element the section goes in.
 */
export function renderRetestsError(slot) {
	const section = document.createElement('div');
	section.className = 'uat-panel__retests';

	const message = document.createElement('p');
	message.className = 'uat-panel__message';
	message.textContent = __(
		'Steps ready to retest could not be loaded.',
		'uat-checklist'
	);

	section.append(message);
	slot.replaceChildren(section);
}

/**
 * Opens a step in the checklist shown in the panel body, moves focus to its summary and brings it into view.
 *
 * Opening it closes the others, which share a `name`. Does nothing when the checklist has no such step.
 *
 * @param {HTMLElement} body   Panel body.
 * @param {string}      stepId The step's UUID.
 */
export function openStep(body, stepId) {
	const details = body
		.querySelector(`form[data-step-id="${stepId}"]`)
		?.closest('.uat-checklist__details');

	if (!details) {
		return;
	}

	details.open = true;

	const summary = details.querySelector('.uat-checklist__summary');
	// Focus moves with the tester's choice, as it does when a save opens the next step. Otherwise a keyboard user is
	// left on a button the list may since have replaced.
	summary.focus({ preventScroll: true });
	// After the layout settles, as when a tester opens a step themselves, since the step that closed shifts it.
	window.requestAnimationFrame(() => scrollToTop(summary));
}
