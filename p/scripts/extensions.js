'use strict';

(function () {
	const search = document.getElementById('extension-search');
	const catalog = document.getElementById('extension-catalog');
	if (!search || !catalog) return;
	const filter = () => {
		const query = search.value.trim().toLocaleLowerCase();
		document.querySelectorAll('#extension-installed .manage-list > li, #extension-catalog tr:has(td)').forEach(row => {
			row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
		});
	};
	search.addEventListener('input', filter);
	if (!catalog.dataset.catalogUrl || navigator.onLine === false) return;
	const controller = new AbortController();
	const timeout = setTimeout(() => controller.abort(), 10000);
	catalog.setAttribute('aria-busy', 'true');
	fetch(catalog.dataset.catalogUrl, { signal: controller.signal, credentials: 'same-origin' })
		.then(response => {
			if (!response.ok || response.redirected) throw new Error('Catalogue unavailable');
			return response.text();
		})
		.then(html => { catalog.innerHTML = html; filter(); })
		.catch(() => { /* Keep the last usable catalogue when offline or the request fails. */ })
		.finally(() => { clearTimeout(timeout); catalog.removeAttribute('aria-busy'); });
}());
