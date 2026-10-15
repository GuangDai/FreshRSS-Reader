import { expect } from '@playwright/test';

export function appearanceGroup(page, key) {
	return page.locator(`fieldset.appearance-choice-group[data-preference="${key}"]`);
}

export function appearanceChoice(page, key, value) {
	return appearanceGroup(page, key).locator(`label[data-choice-value="${value}"]`);
}

export function selectedAppearance(page, key) {
	return appearanceGroup(page, key).locator('input[type="radio"]:checked');
}

export async function expandThemeChoices(page, key = 'theme') {
	const group = appearanceGroup(page, key);
	const toggle = group.locator(`[data-toggle-theme-gallery="${key}"]`);
	await expect(toggle).toBeVisible();
	if (await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
	await expect(toggle).toHaveAttribute('aria-expanded', 'true');
	return group;
}

/** Browse through the real controls without changing the selected theme. */
export async function revealAppearanceChoice(page, key, value) {
	const group = appearanceGroup(page, key);
	const choice = appearanceChoice(page, key, value);
	if (['theme', 'lightTheme', 'darkTheme'].includes(key) && !await choice.isVisible()) {
		await expandThemeChoices(page, key);
		await group.locator('[data-theme-filter="all"]').click();
		await group.locator(`[data-theme-search="${key}"]`).fill(value);
	}
	await expect(choice).toBeVisible();
	return choice;
}

/** Reveal paged themes before selecting the native radio. */
export async function chooseAppearance(page, key, value) {
	const choice = await revealAppearanceChoice(page, key, value);
	await choice.click();
	await expect(choice.locator('input[type="radio"]')).toBeChecked();
}
