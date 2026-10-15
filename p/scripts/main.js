// @license magnet:?xt=urn:btih:0b31508aeb0634b347b8270c7bee4d411b5d4109&dn=agpl-3.0.txt AGPL-3.0
'use strict';
/* globals close_slider_listener */

// <Polyfills>
if (!document.scrollingElement) document.scrollingElement = document.documentElement;
if (!NodeList.prototype.forEach) NodeList.prototype.forEach = Array.prototype.forEach;
if (!Element.prototype.matches) {
	Element.prototype.matches = Element.prototype.msMatchesSelector || Element.prototype.mozMatchesSelector || Element.prototype.webkitMatchesSelector;
}
if (!Element.prototype.closest) {
	Element.prototype.closest = function (s) {
		let el = this;
		do {
			if (el.matches(s)) return el;
			el = el.parentElement;
		} while (el);
		return null;
	};
}
if (!Element.prototype.remove) Element.prototype.remove = function () { if (this.parentNode) this.parentNode.removeChild(this); };
// </Polyfills>

// <Utils>
// Shared with the management and login scripts.
// eslint-disable-next-line no-unused-vars
function xmlHttpRequestJson(req) {
	let json = req.response;
	if (req.responseType !== 'json') {	// IE11
		try {
			json = JSON.parse(req.responseText);
		} catch (ex) {
			json = null;
		}
	}
	return json;
}
// </Utils>

// <Global context>
/* eslint-disable no-var */
var context;
/* eslint-enable no-var */

(function parseJsonVars() {
	const jsonVars = document.getElementById('jsonVars');
	const json = JSON.parse(jsonVars.innerHTML);
	jsonVars.outerHTML = '';
	context = json.context;
	context.ajax_loading = false;
	context.i18n = json.i18n;
	context.shortcuts = json.shortcuts;
	context.urls = json.urls;
	context.icons = json.icons;
	context.icons.read = decodeURIComponent(context.icons.read);
	context.icons.unread = decodeURIComponent(context.icons.unread);
	context.extensions = json.extensions;
}());

const freshrssGlobalContextLoadedEvent = new Event('freshrss:globalContextLoaded');
document.dispatchEvent(freshrssGlobalContextLoadedEvent);
// </Global context>

function toggle_aside_click() {
	const aside = document.querySelector('.aside');
	const toggle_aside = document.querySelector('#nav_menu_toggle_aside button');
	if (!toggle_aside) {
		return;
	}

	const active = toggle_aside.classList.contains('active');
	const isNarrow = window.matchMedia('(max-width: 840px)').matches;
	if (active) {
		toggle_aside.classList.remove('active');
		aside.classList.remove('visible');
		aside.classList.toggle('is-hidden', !isNarrow);
	} else {
		toggle_aside.classList.add('active');
		aside.classList.add('visible');
		aside.classList.remove('is-hidden');
	}
}

function init_nav_menu() {
	const toggle_aside = document.querySelector('#nav_menu_toggle_aside button');
	if (!toggle_aside) {
		return;
	}

	function sync(e) {
		const active = toggle_aside.classList.contains('active');
		if ((e.matches && active) || (!e.matches && !active)) {
			toggle_aside_click();
		}
	}

	const media = window.matchMedia('(max-width: 840px)');
	media.onchange = sync;

	if (toggle_aside.classList.contains('active')) sync(media);
	const close_aside = [
		document.querySelector('.aside a.toggle_aside'),
		document.querySelector('a.close-aside'), // background of aside (#close)
	];

	toggle_aside.addEventListener('click', toggle_aside_click);
	close_aside.forEach(close => close.addEventListener('click', toggle_aside_click));
}

// <notification>
let notification = null;
let notification_interval = null;
let notification_working = false;

// Shared with the management and login scripts.
// eslint-disable-next-line no-unused-vars
function openNotification(msg, status) {
	if (notification_working === true) {
		return false;
	}
	notification_working = true;
	notification.querySelector('.msg').innerHTML = msg;

	if (status == 'good') {
		if (context.closeNotification.good > 0) {
			notification_interval = setTimeout(closeNotification, context.closeNotification.good);
		} else {
			notification.classList.add('closed');
			notification_working = false;
		}
	} else {
		// no status or f.e. status = 'bad', give some more time to read
		if (context.closeNotification.good > 0) {
			notification_interval = setTimeout(closeNotification, context.closeNotification.bad);
		}
	}
	notification.className = 'notification';
	notification.classList.add(status);
}

function closeNotification() {
	notification.classList.add('closed');
	clearInterval(notification_interval);
	notification_working = false;
}

function init_notifications() {
	notification = document.getElementById('notification');

	notification.querySelector('.close').addEventListener('click', function (ev) {
		closeNotification();
		ev.preventDefault();
		return false;
	});

	notification.addEventListener('mouseenter', function () {
		clearInterval(notification_interval);
	});

	notification.addEventListener('mouseleave', function () {
		notification_interval = setTimeout(closeNotification, context.closeNotification.mouseLeave);
	});

	if (notification.querySelector('.msg').innerHTML.length > 0) {
		notification_working = true;
		if (notification.classList.contains('good')) {
			notification_interval = setTimeout(closeNotification, context.closeNotification.good);
		} else {
			// no status or f.e. status = 'bad', give some more time to read
			notification_interval = setTimeout(closeNotification, context.closeNotification.bad);
		}
	}
}
// </notification>

function init_confirm_action() {
	document.body.onclick = function (ev) {
		const b = ev.target.closest('.confirm');
		if (b) {
			let str_confirmation = this.getAttribute('data-str-confirm');
			if (!str_confirmation) {
				str_confirmation = context.i18n.confirmation_default;
			}
			return confirm(str_confirmation);
		}
	};
	const slider = document.getElementById('slider');
	if (slider) {
		slider.addEventListener('freshrss:slider-load', function (e) {
			slider.querySelectorAll('button.confirm').forEach(function (b) { b.disabled = false; });
		});
	}
	document.querySelectorAll('button.confirm').forEach(function (b) { b.disabled = false; });
}

function removeFirstLoadSpinner() {
	const first_load = document.getElementById('first_load');
	if (first_load) {
		first_load.remove();
	}
}

function init_csp_alert() {
	if (!context.admin || context.suppress_csp_warning) {
		return;
	}

	try {
		// eslint-disable-next-line no-new-func
		Function();
	} catch (_) {
		// Exit if 'script-src' is set and 'unsafe-eval' isn't set in CSP
		console.info(`If you see a 'unsafe-eval' warning, everything is working as intended:
see https://freshrss.github.io/FreshRSS/en/admins/10_ServerConfig.html#security`);
		return;
	}

	document.body.insertAdjacentHTML('afterbegin', `
	<div class="alert alert-error">
		<span>${context.i18n.unsafe_csp_header}</span>
	</div>
	`);
}

function init_navigation_handler() {
	if (!('navigation' in window)) {
		return;
	}
	window.navigation.addEventListener('navigate', (e) => {
		if (!(e.canIntercept && e.hashChange && e.navigationType === 'traverse')) {
			return;
		}

		if (location.hash.substr(1) === 'slider' && !close_slider_listener()) {
			e.preventDefault();
		}
	});
}

function init_main_afterDOM() {
	removeFirstLoadSpinner();
	init_notifications();
	init_csp_alert();
	init_confirm_action();
	init_nav_menu();
	init_navigation_handler();
}

history.scrollRestoration = 'manual';
document.scrollingElement.scrollTop = 0;

if (document.readyState && document.readyState !== 'loading') {
	init_main_afterDOM();
} else {
	document.addEventListener('DOMContentLoaded', init_main_afterDOM, false);
}
// @license-end
