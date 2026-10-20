/*
 * Opening and closing a floating panel from one or more toggles, remembering the state for the tab.
 */

/**
 * Wires a panel to its toggles and close button, and restores the state saved under `storageKey`.
 *
 * @param {Object}        options               Panel options.
 * @param {HTMLElement}   options.panel         Element shown and hidden.
 * @param {HTMLElement[]} options.toggles       Controls that open and close the panel.
 * @param {HTMLElement[]} [options.pressed]     Toggles that report the state with aria-pressed instead of
 *                                              aria-expanded, because something else owns their aria-expanded.
 * @param {HTMLElement}   options.closeButton   Control inside the panel that closes it.
 * @param {HTMLElement}   options.focusTarget   Element focused when the panel opens.
 * @param {string}        options.storageKey    sessionStorage key the open state is kept under.
 * @param {Function}      options.onOpen        Called each time the panel opens, including on restore.
 * @param {Function}      [options.onToggle]    Called with the new state each time it changes, including on restore.
 * @param {boolean}       options.openByDefault Open on load when nothing is stored under `storageKey` yet.
 * @param {boolean}       options.inWindow      The panel fills a pop-out: Close closes the window, Escape does nothing.
 * @return {Function} setOpen(open, { restoring }). `restoring: true` neither moves focus nor records the state.
 */
export default function createPanel({
	panel,
	toggles,
	pressed = [],
	closeButton,
	focusTarget,
	storageKey,
	onOpen,
	onToggle = () => {},
	openByDefault,
	inWindow,
}) {
	let lastToggle = toggles[0];

	const setOpen = (open, { restoring = false } = {}) => {
		panel.hidden = !open;
		toggles.forEach((toggle) =>
			toggle.setAttribute(
				pressed.includes(toggle) ? 'aria-pressed' : 'aria-expanded',
				String(open)
			)
		);
		onToggle(open);

		if (open) {
			onOpen();
		}

		if (restoring) {
			return;
		}

		window.sessionStorage.setItem(storageKey, open ? '1' : '');
		(open ? focusTarget : lastToggle).focus();
	};

	toggles.forEach((toggle) => {
		toggle.setAttribute('aria-controls', panel.id);
		toggle.addEventListener('click', (event) => {
			event.preventDefault();
			lastToggle = toggle;
			setOpen(panel.hidden);
		});
	});

	closeButton.addEventListener('click', () => {
		// In the always-on-top window the panel is in a frame, so Close closes the window around it.
		if (inWindow && window.top !== window) {
			window.top.close();
			return;
		}

		if (inWindow) {
			window.close();
		}

		// Still open when the browser refuses to let a script close this window: shut the panel instead.
		if (!window.closed) {
			setOpen(false);
		}
	});
	panel.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && !inWindow) {
			setOpen(false);
		}
	});

	// Restoring records nothing, so a page loaded with its panel shut never writes a "closed" a later window
	// could inherit.
	const stored = window.sessionStorage.getItem(storageKey);

	setOpen(stored === null ? openByDefault : stored === '1', {
		restoring: true,
	});

	return setOpen;
}
