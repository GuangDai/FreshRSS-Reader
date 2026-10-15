function luminance(rgb) {
	const linear = rgb.map(channel => {
		const value = channel / 255;
		return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
	});
	return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
}

/** WCAG contrast for opaque sRGB colours, expressed as 0–255 RGB channels. */
export function contrastRatio(foregroundRgb, backgroundRgb) {
	const foreground = luminance(foregroundRgb);
	const background = luminance(backgroundRgb);
	return (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05);
}

function mixColour(foreground, fallback, amount) {
	return foreground.map((channel, index) => Math.round(channel + (fallback[index] - channel) * amount));
}

function cssColour(rgb) { return `rgb(${rgb.join(' ')})`; }

function readableAgainst(foreground, backgrounds, fallback, minRatio) {
	const ratio = colour => Math.min(...backgrounds.map(background => contrastRatio(colour, background)));
	if (ratio(foreground) >= minRatio) return cssColour(foreground);
	if (ratio(fallback) < minRatio) {
		// A theme's text colour may not suit its primary button background.
		fallback = [[0, 0, 0], [255, 255, 255]].reduce((best, colour) => ratio(colour) > ratio(best) ? colour : best, fallback);
	}
	// Find the first readable blend, with at most one RGB channel step between candidates.
	for (let step = 1; step <= 255; step++) {
		const candidate = mixColour(foreground, fallback, step / 255);
		if (ratio(candidate) >= minRatio) return cssColour(candidate);
	}
	return cssColour(fallback);
}

/** Preserve a colour when readable; otherwise move it towards the theme's text colour. */
export function readableColour(foregroundRgb, backgroundRgb, fallbackRgb, minRatio = 4.5) {
	return readableAgainst(foregroundRgb, [backgroundRgb], fallbackRgb, minRatio);
}

/** Derived reader colours leave the original daisyUI theme tokens untouched. */
export function appearanceColours(body = document.body) {
	const view = body.ownerDocument.defaultView;
	if (view.matchMedia('(forced-colors: active)').matches) return {};
	const styles = view.getComputedStyle(body);
	const canvas = body.ownerDocument.createElement('canvas');
	canvas.width = canvas.height = 1;
	const context = canvas.getContext('2d', { willReadFrequently: true });
	if (!context) return {};
	const read = name => {
		context.clearRect(0, 0, 1, 1);
		context.fillStyle = styles.getPropertyValue(name).trim();
		context.fillRect(0, 0, 1, 1);
		return Array.from(context.getImageData(0, 0, 1, 1).data).slice(0, 3);
	};
	const surface = read('--color-base-100');
	const content = read('--color-base-content');
	const primary = read('--color-primary');
	const backgrounds = [surface, read('--color-base-200'), read('--accent-soft')];
	return {
		'--accent': readableAgainst(primary, backgrounds, content, 4.5),
		'--muted': readableAgainst(mixColour(content, surface, 0.3), backgrounds, content, 4.5),
		'--on-accent': readableColour(read('--color-primary-content'), primary, content),
		'--error': readableAgainst(read('--color-error'), backgrounds, content, 4.5)
	};
}
