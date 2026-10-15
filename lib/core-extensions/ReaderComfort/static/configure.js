'use strict';

// Delegation also works when the extension form arrives in the settings slider.
if (!window.readerComfortPreviewInstalled) {
	window.readerComfortPreviewInstalled = true;
	const update = form => {
		const preview = form.querySelector('.reader-preview');
		form.querySelectorAll('input[type="range"]').forEach(input => {
			const value = Number(input.value);
			form.querySelector('output[for="' + input.id + '"]').value = value;
			preview.style.setProperty('--reader-' + input.name.replaceAll('_', '-'),
				input.name === 'line_height' ? value / 100 : value + 'px');
		});
	};
	document.addEventListener('input', event => {
		const form = event.target.closest('[data-reader-comfort]');
		if (form) update(form);
	});
	document.addEventListener('reset', event => {
		if (event.target.matches('[data-reader-comfort]')) setTimeout(() => update(event.target), 0);
	});
	document.querySelectorAll('[data-reader-comfort]').forEach(update);
}
