// @license magnet:?xt=urn:btih:0b31508aeb0634b347b8270c7bee4d411b5d4109&dn=agpl-3.0.txt AGPL-3.0
'use strict';
/* globals context, panel_focusable_elements, trap_panel_focus, init_archiving, init_configuration_alert, init_password_observers, init_slider, init_url_observers */

// <popup>
let popup = null;
let popup_iframe_container = null;
let popup_iframe = null;
let popup_working = false;
let popup_source = '';
let popup_trigger = null;
let popup_timeout = 0;
let popup_listening = false;

function popup_status(failed = false) {
	const status = popup.querySelector('.panel-status');
	const message = status.querySelector('p');
	message.hidden = false;
	message.setAttribute('role', failed ? 'alert' : 'status');
	message.textContent = failed ? context.i18n.notif_request_failed : context.i18n.loading;
	status.querySelector('button').hidden = !failed;
	status.querySelector('button').textContent = context.i18n.retry;
	popup_iframe_container.setAttribute('aria-busy', failed ? 'false' : 'true');
}

function cancel_popup_request() {
	clearTimeout(popup_timeout);
	popup_timeout = 0;
	popup_working = false;
	if (popup_iframe) {
		popup_iframe.onload = popup_iframe.onerror = null;
		popup_iframe.src = 'about:blank';
	}
}

function openPopupWithSource(source) {
	if (!popup) return false;
	if (popup.style.display !== 'block') popup_trigger = document.activeElement;
	cancel_popup_request();
	popup_source = source;
	popup_working = true;
	popup.style.display = 'block';
	popup_iframe_container.style.display = 'table-row';
	popup_status();
	// A separate frame prevents an older navigation's load event from completing a newer preview.
	const frame = popup_iframe.cloneNode(false);
	popup_iframe.replaceWith(frame);
	popup_iframe = frame;
	const failed = () => {
		if (popup_iframe !== frame || !popup_working) return;
		cancel_popup_request();
		popup_iframe_container.style.display = 'none';
		popup_status(true);
	};
	frame.onload = () => {
		if (popup_iframe !== frame || !popup_working) return;
		try {
			if (frame.contentDocument?.URL === 'about:blank') return;
			if (!frame.contentDocument?.body) { failed(); return; }
			frame.contentDocument.addEventListener('keydown', ev => {
				if (ev.key === 'Escape') { ev.preventDefault(); closePopup(); }
				if (ev.key !== 'Tab') return;
				const controls = panel_focusable_elements(frame.contentDocument);
				const edge = ev.shiftKey ? controls[0] : controls[controls.length - 1];
				if (!edge || frame.contentDocument.activeElement === edge) {
					ev.preventDefault();
					popup.querySelector(ev.shiftKey ? '[data-panel-retry]' : '#popup-close').focus();
				}
			});
		} catch { failed(); return; }
		clearTimeout(popup_timeout);
		popup_timeout = 0;
		popup_working = false;
		popup_iframe_container.setAttribute('aria-busy', 'false');
		popup.querySelector('.panel-status p').hidden = true;
		popup.querySelector('.panel-status button').hidden = false;
		popup.querySelector('.panel-status button').textContent = context.i18n.reload;
	};
	frame.onerror = failed;
	if (context.network.slider_timeout > 0) popup_timeout = setTimeout(failed, context.network.slider_timeout * 1000);
	frame.src = source;
	popup.querySelector('#popup-close').focus({ preventScroll: true });
	return false;
}

function closePopup() {
	if (!popup || popup.style.display !== 'block') return;
	cancel_popup_request();
	popup.style.display = 'none';
	popup_iframe_container.style.display = 'none';
	popup_iframe_container.setAttribute('aria-busy', 'false');
	if (popup_trigger?.isConnected) popup_trigger.focus({ preventScroll: true });
	popup_trigger = null;
}

function init_popup() {
	popup = document.getElementById('popup');
	if (!popup) return;
	popup_iframe_container = document.getElementById('popup-iframe-container');
	popup_iframe = document.getElementById('popup-iframe');
	if (popup.dataset.popupInitialised) return;
	popup.dataset.popupInitialised = '1';
	popup.setAttribute('role', 'dialog');
	popup.setAttribute('aria-modal', 'true');
	popup.setAttribute('aria-label', document.title);
	popup.tabIndex = -1;
	popup_iframe.title = document.title;
	const close = document.getElementById('popup-close');
	close.tabIndex = 0;
	close.setAttribute('role', 'button');
	close.setAttribute('aria-label', context.i18n.close);
	close.addEventListener('click', closePopup);
	close.addEventListener('keydown', ev => {
		if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); closePopup(); }
	});
	const status = document.createElement('div');
	status.className = 'popup-row panel-status';
	status.append(document.createElement('p'));
	const retry = document.createElement('button');
	retry.type = 'button';
	retry.className = 'btn';
	retry.dataset.panelRetry = '';
	retry.textContent = context.i18n.retry;
	retry.addEventListener('click', () => openPopupWithSource(popup_source));
	status.append(retry);
	popup_iframe_container.before(status);
	popup.addEventListener('click', ev => { if (ev.target === popup) closePopup(); });
	if (!popup_listening) {
		popup_listening = true;
		document.addEventListener('freshrss:slider-close', closePopup);
		document.addEventListener('keydown', ev => {
			if (!popup || popup.style.display !== 'block') return;
			if (ev.key === 'Escape') {
				ev.preventDefault();
				ev.stopImmediatePropagation();
				closePopup();
			} else trap_panel_focus(ev, popup);
		}, true);
	}
}
// </popup>

function init_popup_preview_selector() {
	const link = document.getElementById('popup-preview-selector');

	if (!link) {
		return;
	}

	link.addEventListener('click', function (ev) {
		const selector_entries = document.getElementById('path_entries').value;
		const selector_entries_filter = document.getElementById('path_entries_filter').value;
		const href = link.href.replace('selector-token', encodeURIComponent(selector_entries))
			.replace('selector-filter-token', encodeURIComponent(selector_entries_filter));

		openPopupWithSource(href);

		ev.preventDefault();
	});
}

function init_disable_elements_on_update(parent) {
	const inputs = parent.querySelectorAll('input[data-disable-update]');
	for (const input of inputs) {
		input.addEventListener('input', (e) => {
			const elem = document.getElementById(e.target.dataset.disableUpdate);
			if (elem) {
				elem.disabled = true;
				elem.remove();
			}
		});
	}
}

/**
 * Allow a <select class="select-show"> to hide/show elements defined by <option data-show="elem-id"></option>
 */
function init_select_show(parent) {
	const listener = (select) => {
		const options = select.querySelectorAll('option[data-show]');
		const shows = {};	// To allow multiple options to show the same element
		for (const option of options) {
			const targets = option.dataset.show.split(' ');	// Allow multiple targets
			for (const target of targets) {
				if (!shows[target]) {
					shows[target] = option.selected;
				}
			}
		}

		for (const show in shows) {
			const elem = document.getElementById(show);
			if (elem) {
				elem.style.display = shows[show] ? 'block' : 'none';
			}
		}
	};

	const selects = parent.querySelectorAll('select.select-show');
	for (const select of selects) {
		select.addEventListener('change', (e) => listener(e.target));
		listener(select);
	}
}

function init_json_feed_detection(parent) {
	const url = parent.querySelector('#url_rss');
	const kind = parent.querySelector('#feed_kind');
	if (!url || !kind) {
		return;
	}

	const jsonKind = kind.querySelector('option[data-json-feed="1"]');
	const details = kind.closest('details');
	const detect = () => {
		if (kind.value === kind.options[0]?.value && jsonKind && /(?:\b|_)json(?:\b|_)/i.test(url.value)) {
			kind.value = jsonKind.value;
			kind.dispatchEvent(new Event('change', { bubbles: true }));
			if (details) {
				details.open = true;
			}
		}
	};
	url.addEventListener('input', detect);
	detect();
}

/** Automatically validate XPath textarea fields */
function init_valid_xpath(parent) {
	const listener = (textarea) => {
		const evaluator = new XPathEvaluator();
		try {
			if (textarea.value === '' || evaluator.createExpression(textarea.value) != null) {
				textarea.setCustomValidity('');
			}
		} catch (ex) {
			textarea.setCustomValidity(ex);
		}
	};

	const textareas = parent.querySelectorAll('textarea.valid-xpath');
	for (const textarea of textareas) {
		textarea.addEventListener('change', (e) => listener(e.target));
		listener(textarea);
	}
}

function init_feed_afterDOM() {
	if (!window.init_slider || !window.context) {
		if (window.console) {
			console.log('FreshRSS feed waiting for JS…');
		}
		setTimeout(init_feed_afterDOM, 50);
		return;
	}

	const slider = document.getElementById('slider');
	if (slider) {
		slider.addEventListener('freshrss:slider-load', function (e) {
			init_popup();
			init_popup_preview_selector();
			init_select_show(slider);
			init_disable_elements_on_update(slider);
			init_password_observers(slider);
			init_url_observers(slider);
			init_valid_xpath(slider);
		});
		init_slider(slider);
		init_archiving(slider);
	} else {
		init_archiving(document.body);
		init_popup();
		init_popup_preview_selector();
		init_select_show(document.body);
		init_json_feed_detection(document.body);
		init_disable_elements_on_update(document.body);
		init_password_observers(document.body);
		init_valid_xpath(document.body);
	}

	init_configuration_alert();

	if (window.console) {
		console.log('FreshRSS feed init done.');
	}
}

if (document.readyState && document.readyState !== 'loading') {
	init_feed_afterDOM();
} else {
	document.addEventListener('DOMContentLoaded', function () {
		if (window.console) {
			console.log('FreshRSS feed waiting for DOMContentLoaded…');
		}
		init_feed_afterDOM();
	}, false);
}

// @license-end
