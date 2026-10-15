'use strict';

// The settings slider executes this asset for each inserted form; listeners belong to that form only.
(() => {
	const fields = ['title', 'content', 'author', 'link'];
	const MAX_RECIPES = 128;
	const MAX_CANDIDATES = 16;
	const MAX_SELECTORS = 32;
	const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
	const onlyKeys = (value, keys) => object(value) && Object.keys(value).every(key => keys.includes(key));
	const lines = value => value.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
	const safeNumbers = value => {
		const pending = [value];
		while (pending.length) {
			const next = pending.pop();
			if (typeof next === 'number' && !Number.isSafeInteger(next)) return false;
			if (next && typeof next === 'object') for (const item of Object.values(next)) pending.push(item);
		}
		return true;
	};
	const candidateOptions = candidate => ({
		type: candidate.type,
		mode: candidate.mode || 'text',
		attribute: candidate.attribute || '',
		all: candidate.all || false
	});
	const editable = recipe => {
		if (!onlyKeys(recipe, ['format', 'fields', 'remove', 'required']) || !object(recipe.fields)) return false;
		const format = recipe.format === undefined ? 'html' : recipe.format;
		if (!['html', 'json'].includes(format) || Object.keys(recipe.fields).length === 0) return false;
		if (!Object.keys(recipe.fields).every(field => fields.includes(field))) return false;
		for (const candidates of Object.values(recipe.fields)) {
			if (!Array.isArray(candidates) || candidates.length === 0 || candidates.length > MAX_CANDIDATES) return false;
			let shared = null;
			for (const candidate of candidates) {
				if (!onlyKeys(candidate, ['type', 'selector', 'mode', 'attribute', 'all', 'separator']) ||
					typeof candidate.selector !== 'string' || !candidate.selector.trim() || /[\r\n]/.test(candidate.selector)) return false;
				if (!(format === 'json' ? ['json'] : ['css', 'xpath', 'jsonld']).includes(candidate.type)) return false;
				if (candidate.mode !== undefined && !['text', 'html', 'attribute'].includes(candidate.mode)) return false;
				if (candidate.all !== undefined && typeof candidate.all !== 'boolean') return false;
				if (candidate.separator !== undefined && candidate.separator !== '\n') return false;
				if (candidate.type === 'json' && candidate.all) return false;
				if (candidate.mode === 'attribute') {
					if (!['css', 'xpath'].includes(candidate.type) || typeof candidate.attribute !== 'string' || !candidate.attribute.trim()) return false;
				} else if (candidate.attribute !== undefined) return false;
				const options = JSON.stringify(candidateOptions(candidate));
				if (shared !== null && options !== shared) return false;
				shared = options;
			}
		}
		if (recipe.remove !== undefined && (!Array.isArray(recipe.remove) || recipe.remove.length > MAX_SELECTORS ||
			!recipe.remove.every(selector => typeof selector === 'string' && selector.trim() && !/[\r\n]/.test(selector)))) return false;
		if (format === 'json' && recipe.remove?.length) return false;
		return recipe.required === undefined || (Array.isArray(recipe.required) && recipe.required.every(field => Object.hasOwn(recipe.fields, field)));
	};
	const articlePreset = () => ({
		format: 'html',
		fields: {
			title: [{ type: 'css', selector: 'h1' }],
			content: [{ type: 'css', selector: 'article', mode: 'html' }],
			author: [{ type: 'css', selector: '[rel="author"]', all: true }],
			link: [{ type: 'css', selector: 'link[rel="canonical"]', mode: 'attribute', attribute: 'href' }],
		},
		remove: ['nav', 'aside', '.advertisement']
	});
	const jsonPreset = () => ({
		format: 'json',
		fields: {
			title: [{ type: 'json', selector: 'title' }],
			content: [{ type: 'json', selector: 'content_html', mode: 'html' }],
			author: [{ type: 'json', selector: 'authors[0].name' }],
			link: [{ type: 'json', selector: 'url' }],
		}
	});

	document.querySelectorAll('[data-web-extraction]').forEach(form => {
		if (form.dataset.extractionUiReady) return;
		const query = selector => form.querySelector(selector);
		let labels;
		try { labels = JSON.parse(query('[data-extraction-labels]').content.textContent); } catch { return; }
		form.dataset.extractionUiReady = '1';
		form.querySelectorAll('[data-ui-only]').forEach(element => { element.hidden = false; });
		const text = key => labels[key] || key;
		const message = (key, values = {}) => text(key).replace(/\{(\w+)\}/g, (_, name) => String(values[name] ?? ''));
		const raw = query('#extraction-recipes');
		const advanced = query('[data-extraction-advanced]');
		const editor = query('[data-recipe-editor]');
		const targets = query('#extraction-targets');
		const format = query('#extraction-format');
		const rows = Array.from(form.querySelectorAll('[data-extraction-field]'));
		// Visual controls are a projection; only real editor changes should trigger the leave warning.
		editor.querySelectorAll('input, select, textarea').forEach(control => {
			control.dataset.noLeaveValidation = '1'; control.removeAttribute('data-leave-validation');
		});
		const initialRaw = raw.value;
		const initialDraft = form.dataset.extractionDraft === '1';
		const maximumId = form.dataset.maxFeedId;
		let recipes = null;
		let undo = null;
		let editorDirty = false;
		let editorSnapshot = raw.value;
		const notice = (key, error = false) => {
			const target = query(error ? '[data-ui-error]' : '[data-ui-notice]');
			target.textContent = key ? text(key) : '';
			target.hidden = !key;
		};
		const builderError = (key, input = null) => {
			const target = query('[data-builder-error]'); target.textContent = key ? text(key) : ''; target.hidden = !key;
			if (input) input.focus();
			return false;
		};
		const draftStatus = () => {
			const dirty = initialDraft || raw.value !== initialRaw || editorDirty;
			query('[data-draft-status]').textContent = text(dirty ? 'draft' : 'saved');
			query('[data-draft-status]').dataset.dirty = String(dirty);
			query('[data-editor-dirty]').value = initialDraft || editorDirty ? '1' : '0';
		};
		const parse = () => {
			try {
				const value = JSON.parse(raw.value);
				if (!object(value)) throw new Error();
				if (!safeNumbers(value)) { recipes = null; notice('unsafe_numbers', true); advanced.open = true; return false; }
				recipes = value; notice('', true); return true;
			} catch { recipes = null; notice('invalid_json', true); advanced.open = true; return false; }
		};
		const element = (tag, content, className = '') => {
			const result = document.createElement(tag); if (content !== undefined) result.textContent = content;
			if (className) result.className = className;
			return result;
		};
		const render = () => {
			const list = query('[data-recipe-list]'); list.replaceChildren();
			query('[data-recipe-empty]').hidden = recipes === null || Object.keys(recipes).length > 0;
			query('[data-command="undo"]').disabled = undo === null;
			if (recipes) {
				for (const [id, recipe] of Object.entries(recipes)) {
					const row = element('li', undefined, 'extraction-recipe-row');
					const info = element('div', undefined, 'extraction-recipe-info');
					info.append(element('strong', id === '*' ? text('feed_default') : '#' + id));
					const detail = object(recipe) && object(recipe.fields)
						? text(recipe.format === 'json' ? 'format_json' : 'format_html') + ' · ' +
							message('configured_count', { count: Object.keys(recipe.fields).length })
						: text('advanced_recipe');
					info.append(element('span', detail, 'extraction-help'));
					if (!editable(recipe)) info.append(element('span', text('advanced_recipe'), 'extraction-badge'));
					const controls = element('div', undefined, 'extraction-toolbar');
					for (const [command, label] of [['edit', 'edit'], ['remove', 'remove_recipe']]) {
						const button = element('button', text(label), 'btn'); button.type = 'button'; button.dataset.command = command; button.dataset.feed = id;
						button.setAttribute('aria-label', text(label) + ' ' + (id === '*' ? text('feed_default') : '#' + id)); controls.append(button);
					}
					row.append(info, controls); list.append(row);
				}
			}
			draftStatus();
		};
		const syncRow = row => {
			const type = row.querySelector('[data-field-type]'); const mode = row.querySelector('[data-field-mode]');
			for (const option of type.options) option.disabled = format.value === 'json' ? option.value !== 'json' : option.value === 'json';
			if (type.selectedOptions[0].disabled) type.value = format.value === 'json' ? 'json' : 'css';
			mode.querySelector('[value="attribute"]').disabled = !['css', 'xpath'].includes(type.value);
			if (mode.selectedOptions[0].disabled) mode.value = 'text';
			row.querySelector('[data-attribute-label]').hidden = mode.value !== 'attribute';
			const all = row.querySelector('[data-field-all]'); all.disabled = type.value === 'json';
			if (all.disabled) all.checked = false;
		};
		const fill = (recipe = { format: 'html', fields: {} }) => {
			format.value = recipe.format || 'html';
			for (const row of rows) {
				const field = row.dataset.extractionField; const candidates = recipe.fields[field] || [];
				const first = candidates[0] || { type: format.value === 'json' ? 'json' : 'css', mode: field === 'content' ? 'html' : 'text' };
				row.querySelector('[data-field-type]').value = first.type;
				row.querySelector('[data-field-mode]').value = first.mode || 'text';
				row.querySelector('[data-field-selectors]').value = candidates.map(candidate => candidate.selector).join('\n');
				row.querySelector('[data-field-attribute]').value = first.attribute || '';
				row.querySelector('[data-field-all]').checked = first.all || false;
				row.querySelector('[data-field-required]').checked = recipe.required?.includes(field) || false; syncRow(row);
			}
			query('[data-remove-selectors]').value = (recipe.remove || []).join('\n');
			query('[data-cleanup-label]').hidden = format.value !== 'html';
			builderError(''); editor.hidden = false; editorSnapshot = raw.value; editorDirty = false;
		};
		const targetIds = () => {
			const ids = [...new Set(targets.value.split(/[,，\s]+/).filter(Boolean))];
			if (ids.length === 0 || ids.length > MAX_RECIPES || ids.some(id => id !== '*' && (!/^[1-9][0-9]*$/.test(id) ||
				id.length > maximumId.length || (id.length === maximumId.length && id > maximumId)))) return null;
			return ids;
		};
		const buildRecipe = () => {
			const value = { format: format.value, fields: {} }; const required = [];
			for (const row of rows) {
				const field = row.dataset.extractionField; const input = row.querySelector('[data-field-selectors]'); const selectors = lines(input.value);
				if (selectors.length > MAX_CANDIDATES) { builderError('limit_exceeded', input); return null; }
				if (!selectors.length) {
					if (row.querySelector('[data-field-required]').checked) { builderError('required_selector', input); return null; }
					continue;
				}
				const type = row.querySelector('[data-field-type]').value; const mode = row.querySelector('[data-field-mode]').value;
				const attribute = row.querySelector('[data-field-attribute]').value.trim();
				if (mode === 'attribute' && !attribute) { builderError('attribute_required', row.querySelector('[data-field-attribute]')); return null; }
				value.fields[field] = selectors.map(selector => ({
					type,
					selector,
					mode,
					...(mode === 'attribute' ? { attribute } : {}),
					...(row.querySelector('[data-field-all]').checked ? { all: true } : {})
				}));
				if (row.querySelector('[data-field-required]').checked) required.push(field);
			}
			if (!Object.keys(value.fields).length) { builderError('fields_required', rows[0].querySelector('[data-field-selectors]')); return null; }
			const remove = format.value === 'html' ? lines(query('[data-remove-selectors]').value) : [];
			if (remove.length > MAX_SELECTORS) { builderError('limit_exceeded', query('[data-remove-selectors]')); return null; }
			if (remove.length) value.remove = remove;
			if (required.length) value.required = required;
			return value;
		};
		const write = value => {
			undo = raw.value; recipes = value; raw.value = JSON.stringify(value, null, 2); editorSnapshot = raw.value;
			raw.dispatchEvent(new Event('change', { bubbles: true })); render();
		};
		const commit = () => {
			if (editorSnapshot !== raw.value) return builderError('editor_stale');
			if (!parse()) return false;
			const ids = targetIds(); if (!ids) return builderError('targets_required', targets);
			const value = buildRecipe(); if (!value) return false;
			if (ids.some(id => Object.hasOwn(recipes, id) && !editable(recipes[id]))) { advanced.open = true; return builderError('advanced_target'); }
			const updated = { ...recipes }; for (const id of ids) updated[id] = value;
			if (Object.keys(updated).length > MAX_RECIPES) return builderError('limit_exceeded');
			editorDirty = false; write(updated); builderError(''); notice('applied');
			if (!query('#extraction-feed').value) {
				query('#extraction-feed').value = ids[0];
				query('#extraction-feed').dataset.leaveValidation = ids[0];
			}
			return true;
		};
		const open = id => {
			if (editorDirty && !commit()) return;
			if (!parse()) return;
			if (id !== null && !editable(recipes[id])) { advanced.open = true; notice('advanced_only'); raw.focus(); return; }
			fill(id === null ? undefined : recipes[id]); targets.value = id === null ? '' : id; draftStatus(); targets.focus();
		};
		form.addEventListener('click', event => {
			const button = event.target.closest('[data-command]'); if (!button || !form.contains(button)) return;
			const command = button.dataset.command;
			if (command === 'new') open(null);
			if (command === 'edit') open(button.dataset.feed);
			if (command === 'apply') commit();
			if (command === 'cancel') {
				editor.hidden = true; editorDirty = false; builderError(''); draftStatus(); query('[data-command=new]').focus();
			}
			if (command === 'remove') {
				if (editorDirty && !commit()) return;
				if (!parse()) return;
				const updated = { ...recipes }; delete updated[button.dataset.feed]; write(updated); editor.hidden = true; notice('removed');
			}
			if (command === 'undo' && undo !== null) {
				raw.value = undo; undo = null; editorDirty = false; editor.hidden = true; parse(); render(); notice('undone');
				raw.dispatchEvent(new Event('change', { bubbles: true }));
			}
			if (command === 'article-preset' || command === 'json-preset') {
				fill(command === 'article-preset' ? articlePreset() : jsonPreset()); editorDirty = true; draftStatus();
			}
			if (command === 'add-feeds') {
				const selected = Array.from(query('[data-feed-choices]').selectedOptions, option => option.value);
				const values = [...targets.value.split(/[,，\s]+/).filter(Boolean), ...selected];
				targets.value = [...new Set(values)].join(', '); editorDirty = true; draftStatus();
			}
		});
		editor.addEventListener('input', () => { editorDirty = true; draftStatus(); });
		editor.addEventListener('change', event => {
			if (event.target === format) {
				rows.forEach(syncRow); query('[data-cleanup-label]').hidden = format.value !== 'html';
			} else { const row = event.target.closest('[data-extraction-field]'); if (row) syncRow(row); }
			editorDirty = true; draftStatus();
		});
		editor.addEventListener('keydown', event => {
			if (event.key === 'Enter' && event.target.tagName === 'INPUT' && !event.isComposing) { event.preventDefault(); commit(); }
			if (event.key === 'Escape') {
				event.preventDefault(); event.stopPropagation(); editor.hidden = true; editorDirty = false; draftStatus();
				query('[data-command=new]').focus();
			}
		});
		raw.addEventListener('input', () => { parse(); render(); });
		form.addEventListener('submit', event => { if (!editor.hidden && editorDirty && !commit()) event.preventDefault(); });
		parse(); render();
		if (recipes) {
			advanced.open = false;
			const ids = targetIds(); const first = ids?.[0] || (Object.keys(recipes).length === 1 ? Object.keys(recipes)[0] : null);
			if (first && Object.hasOwn(recipes, first) && editable(recipes[first])) {
				const preservedTargets = targets.value; fill(recipes[first]); targets.value = preservedTargets || first;
			} else if (Object.keys(recipes).length === 0) fill();
			else if (first && !editable(recipes[first])) advanced.open = true;
		}
		if (query('#extraction-source').value) query('[data-preview-panel]').open = true;
		const rawResult = query('[data-result-details] pre');
		if (rawResult) {
			try {
				const result = JSON.parse(rawResult.textContent); const output = query('[data-preview-fields]');
				if (!object(result.values) || !Array.isArray(result.diagnostics)) return;
				output.hidden = false;
				output.append(element('h3', result.success ? message('preview_success', { count: Object.keys(result.values).length }) : text('preview_failed')));
				if (result.errors?.length) {
					const errors = element('ul', undefined, 'extraction-error');
					for (const error of result.errors) errors.append(element('li', error));
					output.append(errors);
				}
				for (const field of fields.filter(name => Object.hasOwn(result.values, name) || result.diagnostics.some(item => item.field === name))) {
					const block = element('section', undefined, 'extraction-result-field'); block.dataset.resultField = field;
					block.append(element('h4', text('field_' + field)));
					if (Object.hasOwn(result.values, field)) {
						block.append(element('span', text('mode_' + result.formats[field]), 'extraction-badge'), element('pre', result.values[field]));
					} else block.append(element('p', text('no_value'), 'extraction-help'));
					const trace = element('details'); trace.append(element('summary', text('match_trace'))); const list = element('ol');
					for (const item of result.diagnostics.filter(item => item.field === field)) {
						list.append(element('li', message('trace_' + item.status, {
							index: item.candidate + 1,
							matches: item.matches,
							bytes: item.output_bytes,
							reason: item.reason
						})));
					}
					trace.append(list); block.append(trace); output.append(block);
				}
				query('[data-result-details]').open = false; query('[data-preview-panel]').open = true;
			} catch { query('[data-result-details]').open = true; }
		}
	});
})();
