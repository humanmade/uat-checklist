/*
 * Lets every tab know when the checklist is open in its own window, so their admin bar can show it.
 *
 * The pop-out says when it opens and when it closes, and answers a tab that asks. There is no heartbeat: browsers
 * slow the timers of a window in the background, and a late heartbeat would mark an open window as closed.
 */

const CHANNEL = 'uat-checklist-window';

/**
 * Announces the pop-out window to the site's other tabs, for as long as it stays on this page.
 *
 * @param {string} kind `window` for a normal window, `on-top` for the always-on-top window.
 */
export function announceWindow(kind) {
	const channel = new window.BroadcastChannel(CHANNEL);
	const say = (type) => channel.postMessage({ type, kind });

	say('open');
	channel.addEventListener('message', (event) => {
		if (event.data?.type === 'ask') {
			say('open');
		}
	});
	// Fires when the window closes and when it moves to another page; the next page announces itself again.
	window.addEventListener('pagehide', () => say('closed'));
}

/**
 * Calls back whenever the pop-out window opens or closes, and once on load if one is already open.
 *
 * @param {Function} onChange Called with `window`, `on-top`, or '' once no pop-out is open.
 */
export function watchWindow(onChange) {
	const channel = new window.BroadcastChannel(CHANNEL);
	let current = '';

	channel.addEventListener('message', ({ data }) => {
		const next = data?.type === 'open' ? data.kind : '';

		if (data?.type !== 'ask' && next !== current) {
			current = next;
			onChange(current);
		}
	});
	channel.postMessage({ type: 'ask' });
}
