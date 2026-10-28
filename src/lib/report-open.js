/*
 * How the panel's footer, the floating button and the admin bar item, which two bundles wire, ask src/report.js to
 * open its dialog.
 */

export const REPORT_OPEN_EVENT = 'hm-uat-report:open';

// Marks every control that opens the dialog, so focus can go back to whichever of them is on screen.
export const REPORT_OPENER_ATTRIBUTE = 'data-uat-report-open';

/**
 * Makes a control open the report dialog, and return focus to itself when it closes.
 *
 * @param {HTMLElement} button Button, or the admin bar item's `#` link.
 */
export function bindReportOpener(button) {
	button.setAttribute(REPORT_OPENER_ATTRIBUTE, '');
	button.setAttribute('aria-haspopup', 'dialog');
	button.addEventListener('click', (event) => {
		event.preventDefault();
		document.dispatchEvent(
			new window.CustomEvent(REPORT_OPEN_EVENT, {
				detail: { opener: button },
			})
		);
	});
}
