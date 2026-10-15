import { test } from 'node:test';
import assert from 'node:assert/strict';
import { contrastRatio, readableColour } from '../../../../p/scripts/reader/appearance/colours.js';

const rgb = colour => colour.match(/[\d.]+/g).map(Number);

test('contrast uses linear sRGB luminance and is independent of colour order', () => {
	assert.equal(contrastRatio([0, 0, 0], [255, 255, 255]), 21);
	assert.equal(contrastRatio([40, 80, 120], [40, 80, 120]), 1);
	assert.ok(Math.abs(contrastRatio([0, 0, 255], [255, 255, 255]) - 8.592471358428805) < 1e-10);
	assert.equal(contrastRatio([12, 170, 205], [240, 30, 70]), contrastRatio([240, 30, 70], [12, 170, 205]));
});

test('a readable theme colour is retained without modifying any input', () => {
	const foreground = Object.freeze([40, 30, 55]);
	const background = Object.freeze([255, 255, 255]);
	const fallback = Object.freeze([0, 0, 0]);
	assert.equal(readableColour(foreground, background, fallback), 'rgb(40 30 55)');
});

test('pastel links are darkened only until normal text is readable', () => {
	const colour = rgb(readableColour([190, 190, 190], [255, 255, 255], [0, 0, 0]));
	assert.deepEqual(colour, [118, 118, 118]);
	assert.ok(contrastRatio(colour, [255, 255, 255]) >= 4.5);
	assert.ok(contrastRatio([119, 119, 119], [255, 255, 255]) < 4.5);
});

test('a low-contrast accent on a dark surface moves towards its light text colour', () => {
	const foreground = [48, 75, 120];
	const fallback = [235, 240, 250];
	const background = [20, 25, 36];
	const colour = rgb(readableColour(foreground, background, fallback));
	assert.ok(contrastRatio(colour, background) >= 4.5);
	colour.forEach((channel, index) => assert.ok(channel >= foreground[index] && channel <= fallback[index]));
});

test('an unsuitable theme text fallback uses the more readable black or white', () => {
	const background = [128, 128, 128];
	const colour = rgb(readableColour([125, 125, 125], background, [130, 130, 130]));
	assert.ok(contrastRatio(colour, background) >= 4.5);
	assert.ok(colour.every(channel => channel < 125));
});

test('button text is corrected against the original bright primary background', () => {
	const primary = [255, 80, 160];
	const colour = rgb(readableColour([255, 255, 255], primary, [35, 25, 40]));
	assert.ok(contrastRatio(colour, primary) >= 4.5);
	assert.notDeepEqual(colour, [255, 255, 255]);
});

test('a stronger requested contrast ratio is respected', () => {
	const background = [255, 255, 255];
	assert.ok(contrastRatio(rgb(readableColour([160, 100, 170], background, [0, 0, 0], 7)), background) >= 7);
});
