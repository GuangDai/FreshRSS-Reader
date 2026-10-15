'use strict';

(() => {
	const configElement = document.getElementById('reader-settings-embed-config');
	if (!configElement || window.parent === window) return;
	const config = JSON.parse(configElement.textContent);
	let dirty = false;
	let lastHeight = 0;
	let resizeFrame = 0;
	const initialValues = new WeakMap();
	const current = new URL(window.location.href);
	const route = {
		c: current.searchParams.get('c') || config.defaultController,
		a: current.searchParams.get('a') || config.defaultAction,
	};
	const send = (event, data = {}) => window.parent.postMessage({
		type: 'reader-settings-embed', event, title: document.title, route, dirty, ...data,
	}, window.location.origin);
	const allowed = (url, post = false) => {
		if (url.origin !== window.location.origin || url.pathname !== current.pathname) return false;
		const controller = url.searchParams.get('c') || config.defaultController;
		const action = url.searchParams.get('a') || config.defaultAction;
		return (config.pages[controller] || []).includes(action) || (post && (config.submits[controller] || []).includes(action));
	};
	const sensitive = url => Boolean(url.username || url.password ||
		['token', 'password', 'apiPasswordPlain', 'passwordPlain', 'feverKey'].some(name => url.searchParams.has(name)));
	const standaloneSubmission = url => url.searchParams.get('a') === 'viewFilter';
	const destination = value => {
		try { return new URL(value, window.location.href); } catch { return null; }
	};
	const valueOf = control => {
		if (['checkbox', 'radio'].includes(control.type)) return control.checked;
		if (control.multiple && control.selectedOptions) return JSON.stringify(Array.from(control.selectedOptions, option => option.value));
		return control.value;
	};
	const rememberValues = root => root.querySelectorAll('form input, form select, form textarea').forEach(control => {
		if (!initialValues.has(control)) initialValues.set(control, valueOf(control));
	});
	const updateDirty = () => {
		const changed = Array.from(document.querySelectorAll('form input, form select, form textarea'))
			.some(control => initialValues.has(control) && initialValues.get(control) !== valueOf(control));
		if (dirty !== changed) {
			dirty = changed;
			send('dirty');
		}
	};
	const field = (form, name, value) => {
		if (form.querySelector(`input[name="${name}"]`)) return;
		const input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		form.append(input);
	};
	const enhance = root => {
		root.querySelectorAll('a[href]').forEach(link => {
			const value = link.getAttribute('href');
			if (!value || value.startsWith('#') || link.hasAttribute('download')) return;
			const url = destination(value);
			if (!url || !['http:', 'https:'].includes(url.protocol)) return;
			if (allowed(url)) {
				url.searchParams.set('readerSettings', '1');
				link.href = url.href;
			} else if (url.origin !== current.origin || sensitive(url)) {
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
			}
		});
		root.querySelectorAll('form').forEach(form => {
			const url = destination(form.getAttribute('action') || window.location.href);
			if (!url) return;
			const post = form.method.toLowerCase() === 'post';
			if (allowed(url, post) && !standaloneSubmission(url)) {
				url.searchParams.set('readerSettings', '1');
				form.action = url.href;
				if (!post) {
					field(form, 'readerSettings', '1');
					field(form, 'c', url.searchParams.get('c') || config.defaultController);
					field(form, 'a', url.searchParams.get('a') || config.defaultAction);
				}
			} else {
				url.searchParams.delete('readerSettings'); form.action = url.href;
				form.target = '_blank';
			}
		});
		root.querySelectorAll('[formaction]').forEach(button => {
			const url = destination(button.getAttribute('formaction'));
			if (!url) return;
			if (allowed(url, true) && !standaloneSubmission(url)) {
				url.searchParams.set('readerSettings', '1');
				button.setAttribute('formaction', url.href);
			} else {
				url.searchParams.delete('readerSettings'); button.setAttribute('formaction', url.href);
				button.setAttribute('formtarget', '_blank');
			}
		});
		rememberValues(root);
	};
	const resize = () => {
		cancelAnimationFrame(resizeFrame);
		resizeFrame = requestAnimationFrame(() => {
			const height = Math.ceil(Math.max(document.body.scrollHeight, document.body.getBoundingClientRect().height));
			if (height !== lastHeight) {
				lastHeight = height;
				send('resize', { height });
			}
		});
	};
	enhance(document);
	document.addEventListener('click', event => {
		if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		const link = event.target.closest('a[href]');
		if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
		const url = destination(link.getAttribute('href'));
		if (!url || !['http:', 'https:'].includes(url.protocol)) return;
		if (url.origin === current.origin && url.pathname === current.pathname && url.search === current.search) return;
		if (allowed(url)) {
			if (dirty) {
				if (!window.confirm(config.confirmExit)) {
					event.preventDefault(); event.stopImmediatePropagation(); return;
				}
				window.hasSubmit = true;
			}
			event.preventDefault(); event.stopImmediatePropagation();
			window.location.replace(url.href);
			return;
		}
		event.preventDefault();
		url.searchParams.delete('readerSettings');
		send('navigate', { url: url.href });
	}, true);
	document.addEventListener('input', updateDirty);
	document.addEventListener('change', updateDirty);
	document.addEventListener('reset', () => requestAnimationFrame(updateDirty));
	new MutationObserver(records => {
		for (const record of records) {
			for (const node of record.addedNodes) {
				if (node.nodeType !== Node.ELEMENT_NODE) continue;
				enhance(node.parentElement || document);
			}
		}
		resize();
	}).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden', 'open'] });
	new ResizeObserver(resize).observe(document.body);
	window.addEventListener('pagehide', () => cancelAnimationFrame(resizeFrame));
	window.addEventListener('pageshow', () => { send('navigation'); resize(); });
	window.addEventListener('popstate', () => send('navigation'));
	send('ready');
	if (document.querySelector('#notification.good, #notification.success')) send('saved');
	resize();
})();
