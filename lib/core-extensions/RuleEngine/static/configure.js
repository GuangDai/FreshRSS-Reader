'use strict';

// Each mounted form owns its listeners, including forms loaded inside the settings slider.
(() => {
	const MAX_VISUAL_CONDITIONS = 8;
	const MAX_VISUAL_DEPTH = 16;
	const textOperators = ['contains', 'equals', 'starts_with', 'ends_with', 'empty'];
	const numericOperators = ['gt', 'gte', 'lt', 'lte'];
	const numericFields = ['content_length', 'published_at'];
	const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
	const clip = value => String(value).length > 140 ? String(value).slice(0, 139) + '…' : String(value);
	const safeNumbers = value => {
		const pending = [value];
		while (pending.length) {
			const next = pending.pop();
			if (typeof next === 'number' && !Number.isSafeInteger(next)) return false;
			if (next && typeof next === 'object') for (const item of Object.values(next)) pending.push(item);
		}
		return true;
	};
	const onlyKeys = (value, keys) => object(value) && Object.keys(value).every(key => keys.includes(key));

	document.querySelectorAll('[data-rule-engine]').forEach(form => {
		if (form.dataset.ruleUiReady) return;
		const query = selector => form.querySelector(selector);
		let labels; let presets;
		try {
			labels = JSON.parse(query('[data-rule-labels]').content.textContent);
			presets = JSON.parse(query('[data-rule-presets]').content.textContent);
		} catch { return; }
		form.dataset.ruleUiReady = '1';
		form.querySelectorAll('[data-ui-only]').forEach(element => { element.hidden = false; });
		const text = key => labels[key] || key;
		const rulesInput = query('#rules-json');
		const sampleInput = query('#sample-json');
		const initialRaw = rulesInput.value;
		const initialDraft = form.dataset.ruleDraft === '1';
		const advanced = query('[data-rule-advanced]');
		const editor = query('[data-rule-editor]');
		const list = query('[data-rule-list]');
		let documentValue = null;
		let selected = new Set();
		let undoValue = null;
		let internalWrite = false;
		let editing = null;
		let editorDirty = false;

		const notice = (key, error = false) => {
			const element = query(error ? '[data-ui-error]' : '[data-ui-message]');
			element.textContent = key ? text(key) : '';
			element.hidden = !key;
		};
		const draftStatus = () => {
			const dirty = initialDraft || editorDirty || rulesInput.value !== initialRaw;
			const element = query('[data-draft-status]');
			element.textContent = text(dirty ? 'draft' : 'saved');
			element.dataset.dirty = String(dirty);
		};
		const leaves = (node, context = [], result = [], depth = 0) => {
			if (!object(node) || depth > MAX_VISUAL_DEPTH || result.length > MAX_VISUAL_CONDITIONS) return null;
			for (const group of ['all', 'any', 'not']) {
				if (!Object.hasOwn(node, group)) continue;
				if (!onlyKeys(node, [group])) return null;
				const children = group === 'not' ? [node.not] : node[group];
				if (!Array.isArray(children) || children.length === 0) return null;
				for (const child of children) {
					if (!leaves(child, [...context, text(group)], result, depth + 1)) return null;
				}
				return result.length <= MAX_VISUAL_CONDITIONS ? result : null;
			}
			if (!onlyKeys(node, ['field', 'op', 'value', 'case_sensitive', 'min_matches']) || !labels['field_' + node.field]) return null;
			const numeric = numericFields.includes(node.field);
			if (!(numeric ? numericOperators : textOperators).includes(node.op)) return null;
			if (node.op !== 'empty' && (numeric ? !Number.isSafeInteger(node.value) : typeof node.value !== 'string')) return null;
			result.push({ node, context });
			return result.length <= MAX_VISUAL_CONDITIONS ? result : null;
		};
		const editable = rule => {
			if (!onlyKeys(rule, ['id', 'enabled', 'when', 'actions', 'scope', 'stop']) || typeof rule.id !== 'string') return false;
			if (!onlyKeys(rule.actions, ['read', 'star', 'add_tags'])) return false;
			if (['read', 'star'].some(key => Object.hasOwn(rule.actions, key) && rule.actions[key] !== true)) return false;
			if (Object.hasOwn(rule.actions, 'add_tags') && (!Array.isArray(rule.actions.add_tags) ||
				!rule.actions.add_tags.every(tag => typeof tag === 'string'))) return false;
			if (rule.scope !== undefined) {
				if (!onlyKeys(rule.scope, ['feed_ids', 'exclude_feed_ids', 'states'])) return false;
				for (const key of ['feed_ids', 'exclude_feed_ids']) {
					if (Object.hasOwn(rule.scope, key) && (!Array.isArray(rule.scope[key]) ||
						!rule.scope[key].every(id => Number.isSafeInteger(id) && id > 0))) return false;
				}
				if (Object.hasOwn(rule.scope, 'states') && (!Array.isArray(rule.scope.states) ||
					!rule.scope.states.every(state => ['new', 'updated'].includes(state)))) return false;
			}
			return leaves(rule.when) !== null;
		};
		const summary = rule => {
			const conditions = leaves(rule.when);
			if (!conditions) return text('advanced_rule');
			return conditions.map(({ node, context }) => [...context, text('field_' + node.field), text('op_' + node.op),
				node.op === 'empty' ? '' : clip(node.value)].filter(Boolean).join(' ')).join(' · ');
		};
		const actionsSummary = actions => {
			const parts = [];
			if (actions.read) parts.push(text('read_action'));
			if (actions.star) parts.push(text('star_action'));
			if (Array.isArray(actions.add_tags)) parts.push(text('tags_action') + ': ' + clip(actions.add_tags.join(', ')));
			return parts.join(' · ') || text('no_actions');
		};
		const button = (label, command, index) => {
			const element = document.createElement('button');
			element.type = 'button'; element.className = 'btn'; element.textContent = label;
			element.dataset.ruleCommand = command;
			if (index !== undefined) element.dataset.ruleIndex = String(index);
			return element;
		};
		const syncSelection = () => {
			query('[data-selection-count]').textContent = selected.size ? text('selected').replace('{count}', selected.size) : '';
			for (const command of ['enable-selected', 'disable-selected', 'delete-selected']) {
				const control = query('[data-rule-command="' + command + '"]');
				control.disabled = !documentValue || selected.size === 0;
				control.hidden = selected.size === 0;
			}
			query('.rule-bulk').hidden = !documentValue || documentValue.rules.length === 0;
			const all = query('[data-select-all]');
			all.disabled = !documentValue || documentValue.rules.length === 0;
			all.checked = !!documentValue && documentValue.rules.length > 0 && selected.size === documentValue.rules.length;
			all.indeterminate = selected.size > 0 && !all.checked;
		};
		const render = () => {
			list.replaceChildren();
			query('[data-rule-count]').textContent = documentValue ? '(' + documentValue.rules.length + ')' : '';
			query('[data-rule-empty]').hidden = !documentValue || documentValue.rules.length > 0;
			query('[data-rule-command="add"]').disabled = !documentValue;
			query('[data-rule-command="preset"]').disabled = !documentValue;
			query('[data-rule-command="undo"]').disabled = undoValue === null;
			query('[data-rule-command="undo"]').hidden = undoValue === null;
			if (documentValue) {
				documentValue.rules.forEach((rule, index) => {
					const row = document.createElement('li'); row.dataset.ruleRow = String(index);
					const selection = document.createElement('input'); selection.type = 'checkbox';
					selection.dataset.selectRule = String(index); selection.checked = selected.has(index);
					selection.setAttribute('aria-label', text('select_rule') + ' ' + clip(rule.id));
					const content = document.createElement('div'); content.className = 'rule-row-main';
					const heading = button(clip(rule.id || text('untitled')), 'edit', index); heading.className = 'rule-row-title';
					const condition = document.createElement('p'); condition.textContent = clip(summary(rule));
					const actions = document.createElement('p'); actions.className = 'rule-help'; actions.textContent = actionsSummary(rule.actions);
					if (!editable(rule)) actions.textContent += ' · ' + text('advanced_rule');
					content.append(heading, condition, actions);
					const controls = document.createElement('div'); controls.className = 'rule-row-controls';
					const toggle = button(text(rule.enabled === false ? 'disabled' : 'enabled'), 'toggle', index);
					toggle.setAttribute('aria-pressed', String(rule.enabled !== false));
					toggle.setAttribute('aria-label', clip(rule.id) + ' · ' + text(rule.enabled === false ? 'enable' : 'disable'));
					const up = button(text('up'), 'up', index); up.disabled = index === 0;
					const down = button(text('down'), 'down', index); down.disabled = index === documentValue.rules.length - 1;
					controls.append(toggle, up, down); row.append(selection, content, controls); list.append(row);
				});
			}
			syncSelection(); draftStatus();
		};
		const parse = () => {
			try {
				const value = JSON.parse(rulesInput.value);
				if (!object(value) || value.version !== 1 || !Array.isArray(value.rules) ||
					!value.rules.every(rule => object(rule) && typeof rule.id === 'string' && object(rule.when) && object(rule.actions))) {
					throw new Error('invalid');
				}
				if (!safeNumbers(value)) { documentValue = null; notice('unsafe_numbers', true); advanced.open = true; return; }
				documentValue = value; notice('', true);
			} catch { documentValue = null; notice('invalid_json', true); advanced.open = true; }
		};
		const write = (value, message = 'updated_draft') => {
			undoValue = rulesInput.value;
			documentValue = value; rulesInput.value = JSON.stringify(value, null, 2); selected.clear();
			internalWrite = true; rulesInput.dispatchEvent(new Event('input', { bubbles: true })); internalWrite = false;
			notice(message); notice('', true); render();
		};
		const editorBaseline = () => editor.querySelectorAll('input, select, textarea').forEach(input => {
			input.dataset.leaveValidation = input.type === 'checkbox' ? String(Number(input.checked)) : input.value;
		});
		const closeEditor = () => { editor.hidden = true; editing = null; editorDirty = false; editorBaseline(); draftStatus(); };
		const updateOperator = (chosen = null) => {
			const field = query('[data-editor-field]').value;
			const operators = numericFields.includes(field) ? numericOperators : textOperators;
			const select = query('[data-editor-op]');
			const previous = chosen || select.value; select.replaceChildren();
			operators.forEach(op => {
				const option = document.createElement('option');
				option.value = op; option.textContent = text('op_' + op); select.append(option);
			});
			select.value = operators.includes(previous) ? previous : operators[0];
			const numeric = numericFields.includes(field);
			const value = query('[data-editor-value]'); value.type = numeric ? 'number' : 'text'; value.step = '1';
			if (field === 'content_length') value.min = '0'; else value.removeAttribute('min');
			query('[data-editor-value-label]').hidden = select.value === 'empty';
			query('[data-editor-case-label]').hidden = numeric || !!editing?.compound;
			query('[data-editor-minimum-label]').hidden = select.value !== 'contains' || !!editing?.compound;
		};
		const uniqueId = base => {
			const names = new Set(documentValue.rules.map(rule => rule.id));
			if (!names.has(base)) return base;
			let suffix = 2; while (names.has(base + '-' + suffix)) suffix++;
			return base + '-' + suffix;
		};
		const openEditor = (rule, index = null) => {
			if (!editable(rule)) {
				advanced.open = true; rulesInput.focus(); notice('advanced_only'); return;
			}
			const value = structuredClone(rule);
			const conditions = leaves(value.when);
			editing = { value, index, base: rulesInput.value, compound: !Object.hasOwn(value.when, 'field'), conditions };
			editorDirty = index === null;
			query('[data-editor-heading]').textContent = text(index === null ? 'new_rule' : 'edit_rule');
			query('[data-builder-error]').hidden = true;
			query('[data-editor-id]').value = value.id;
			query('[data-simple-condition]').hidden = editing.compound;
			query('[data-compound-note]').hidden = !editing.compound;
			const compound = query('[data-compound-values]'); compound.replaceChildren(); compound.hidden = !editing.compound;
			if (editing.compound) {
				conditions.forEach(({ node, context }, position) => {
					const label = document.createElement('label'); label.className = 'rule-wide';
					label.textContent = [...context, text('field_' + node.field), text('op_' + node.op)].join(' · ');
					if (node.op !== 'empty') {
						const input = document.createElement('input'); input.type = numericFields.includes(node.field) ? 'number' : 'text';
						input.value = String(node.value); input.dataset.compoundValue = String(position); label.append(input);
					}
					compound.append(label);
				});
			} else {
				query('[data-editor-field]').value = value.when.field;
				updateOperator(value.when.op); query('[data-editor-value]').value = value.when.value ?? '';
			}
			query('[data-editor-case]').checked = value.when.case_sensitive === true;
			query('[data-editor-minimum]').value = value.when.min_matches || 1;
			for (const action of ['read', 'star', 'add_tags']) query('[data-editor-action="' + action + '"]').checked = !!value.actions[action];
			query('[data-editor-tags]').value = (value.actions.add_tags || []).join('\n');
			query('[data-editor-tags-label]').hidden = !value.actions.add_tags;
			query('[data-editor-feeds]').value = (value.scope?.feed_ids || []).join(', ');
			query('[data-editor-excluded]').value = (value.scope?.exclude_feed_ids || []).join(', ');
			query('[data-editor-state]').value = value.scope?.states?.length === 1 ? value.scope.states[0] : 'both';
			query('[data-editor-enabled]').checked = value.enabled !== false;
			query('[data-editor-stop]').checked = value.stop === true;
			if (editing.compound) {
				query('[data-editor-case-label]').hidden = true; query('[data-editor-minimum-label]').hidden = true;
			}
			editor.hidden = false; editorBaseline(); query('[data-editor-id]').focus(); draftStatus();
		};
		const builderError = (key, element) => {
			const error = query('[data-builder-error]'); error.textContent = text(key); error.hidden = false;
			element?.focus(); return false;
		};
		const commitEditor = () => {
			if (!editing) return true;
			if (!documentValue || editing.base !== rulesInput.value) return builderError('editor_stale', query('[data-editor-id]'));
			const id = query('[data-editor-id]').value.trim();
			if (!id) return builderError('name_required', query('[data-editor-id]'));
			if (documentValue.rules.some((rule, index) => index !== editing.index && rule.id === id)) {
				return builderError('duplicate_name', query('[data-editor-id]'));
			}
			const rule = structuredClone(editing.value); rule.id = id;
			if (editing.compound) {
				const conditions = leaves(rule.when);
				for (const input of query('[data-compound-values]').querySelectorAll('input')) {
					const condition = conditions[Number(input.dataset.compoundValue)].node;
					if (!input.value) return builderError('value_required', input);
					condition.value = numericFields.includes(condition.field) ? Number(input.value) : input.value;
					if (numericFields.includes(condition.field) && (!Number.isSafeInteger(condition.value) ||
						(condition.field === 'content_length' && condition.value < 0))) return builderError('integer_required', input);
				}
			} else {
				const field = query('[data-editor-field]').value; const op = query('[data-editor-op]').value;
				const value = query('[data-editor-value]'); rule.when = { field, op };
				if (op !== 'empty') {
					if (!value.value) return builderError('value_required', value);
					rule.when.value = numericFields.includes(field) ? Number(value.value) : value.value;
					if (numericFields.includes(field) && (!Number.isSafeInteger(rule.when.value) || (field === 'content_length' && rule.when.value < 0))) {
						return builderError('integer_required', value);
					}
				}
				if (!numericFields.includes(field) && query('[data-editor-case]').checked) rule.when.case_sensitive = true;
				if (op === 'contains') {
					const minimum = Number(query('[data-editor-minimum]').value);
					if (!Number.isSafeInteger(minimum) || minimum < 1) return builderError('integer_required', query('[data-editor-minimum]'));
					if (minimum > 1) rule.when.min_matches = minimum;
				}
			}
			rule.actions = {};
			for (const action of ['read', 'star']) if (query('[data-editor-action="' + action + '"]').checked) rule.actions[action] = true;
			if (query('[data-editor-action="add_tags"]').checked) {
				const tags = query('[data-editor-tags]').value.split('\n').map(tag => tag.trim()).filter(Boolean);
				if (!tags.length || tags.some(tag => /[#,]/.test(tag))) return builderError('tags_required', query('[data-editor-tags]'));
				rule.actions.add_tags = [...new Set(tags)];
			}
			if (!Object.keys(rule.actions).length) return builderError('action_required', query('[data-editor-action="read"]'));
			const scope = {};
			for (const [field, key] of [['feeds', 'feed_ids'], ['excluded', 'exclude_feed_ids']]) {
				const input = query('[data-editor-' + field + ']');
				if (input.value.trim()) {
					const values = input.value.trim().split(/[,\s]+/).map(Number);
					if (values.some(value => !Number.isSafeInteger(value) || value < 1)) return builderError('scope_invalid', input);
					scope[key] = [...new Set(values)];
				}
			}
			if (query('[data-editor-state]').value !== 'both') scope.states = [query('[data-editor-state]').value];
			if (Object.keys(scope).length) rule.scope = scope; else delete rule.scope;
			rule.enabled = query('[data-editor-enabled]').checked; rule.stop = query('[data-editor-stop]').checked;
			const value = structuredClone(documentValue);
			if (editing.index === null) value.rules.push(rule); else value.rules[editing.index] = rule;
			closeEditor(); write(value); return true;
		};
		const syncSample = () => {
			try {
				const value = JSON.parse(sampleInput.value);
				if (!object(value)) return;
				form.querySelectorAll('[data-sample-field]').forEach(input => {
					const field = value[input.dataset.sampleField];
					input.value = Array.isArray(field) ? field.join('\n') : typeof field === 'string' ? field : '';
				});
			} catch { query('[data-sample-advanced]').open = true; }
		};
		const command = (name, index) => {
			if (name === 'cancel-editor') { closeEditor(); return; }
			if (name === 'apply-editor') { commitEditor(); return; }
			if (name === 'undo' && undoValue !== null) {
				closeEditor(); rulesInput.value = undoValue; undoValue = null; selected.clear(); parse(); render(); notice('updated_draft'); return;
			}
			if (!documentValue) return;
			if (editing && editorDirty && ['add', 'preset', 'edit'].includes(name) && !commitEditor()) return;
			if (name === 'add') {
				openEditor({ id: uniqueId(text('new_rule')), when: { field: 'title', op: 'contains', value: '' }, actions: { star: true } }); return;
			}
			if (name === 'preset') {
				const preset = JSON.parse(presets[query('#rule-preset').value]).rules[0];
				preset.id = uniqueId(query('#rule-preset').selectedOptions[0].textContent.trim());
				openEditor(preset); return;
			}
			if (name === 'edit') { openEditor(documentValue.rules[index], index); return; }
			const value = structuredClone(documentValue);
			if (name === 'toggle') value.rules[index].enabled = value.rules[index].enabled === false;
			else if (name === 'up' || name === 'down') {
				const destination = index + (name === 'up' ? -1 : 1);
				if (destination < 0 || destination >= value.rules.length) return;
				[value.rules[index], value.rules[destination]] = [value.rules[destination], value.rules[index]];
				index = destination;
			} else if (name === 'enable-selected' || name === 'disable-selected') {
				selected.forEach(position => { value.rules[position].enabled = name === 'enable-selected'; });
			} else if (name === 'delete-selected') value.rules = value.rules.filter((rule, position) => !selected.has(position));
			else return;
			write(value, name === 'delete-selected' ? 'removed_draft' : 'updated_draft');
			if (Number.isInteger(index)) query('[data-rule-row="' + index + '"] [data-rule-command="' + name + '"]')?.focus();
		};

		form.addEventListener('click', event => {
			const target = event.target.closest('[data-rule-command]');
			if (target) {
				event.preventDefault();
				command(target.dataset.ruleCommand, target.dataset.ruleIndex === undefined ? null : Number(target.dataset.ruleIndex));
			}
		});
		form.addEventListener('change', event => {
			const target = event.target;
			if (target.matches('[data-select-all]')) {
				selected = new Set(target.checked && documentValue ? documentValue.rules.map((rule, index) => index) : []); render();
			} else if (target.matches('[data-select-rule]')) {
				const index = Number(target.dataset.selectRule); if (target.checked) selected.add(index); else selected.delete(index); syncSelection();
			} else if (target.matches('[data-editor-field], [data-editor-op]')) updateOperator();
			else if (target.matches('[data-editor-action="add_tags"]')) query('[data-editor-tags-label]').hidden = !target.checked;
		});
		form.addEventListener('input', event => {
			if (event.target === rulesInput) {
				if (!internalWrite) { undoValue = null; selected.clear(); parse(); render(); }
				draftStatus();
			} else if (editor.contains(event.target)) { editorDirty = true; draftStatus(); } else if (event.target === sampleInput) syncSample();
			else if (event.target.matches('[data-sample-field]')) {
				try {
					const value = JSON.parse(sampleInput.value);
					if (!object(value)) throw new Error('invalid');
					const key = event.target.dataset.sampleField;
					value[key] = key === 'author' ? event.target.value.split('\n').filter(Boolean) : event.target.value;
					sampleInput.value = JSON.stringify(value, null, 2);
				} catch { query('[data-sample-advanced]').open = true; notice('invalid_json', true); }
			}
		});
		editor.addEventListener('keydown', event => {
			if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeEditor(); }
			if (event.key === 'Enter' && event.target.tagName === 'INPUT' && !event.isComposing) { event.preventDefault(); commitEditor(); }
		});
		form.addEventListener('submit', event => { if (editing && editorDirty && !commitEditor()) event.preventDefault(); });
		form.addEventListener('reset', () => queueMicrotask(() => {
			closeEditor(); selected.clear(); undoValue = null; parse(); render(); syncSample();
		}));
		parse(); render(); syncSample();
		if (documentValue) advanced.open = false;
		query('[data-sample-advanced]').open = false;
		const result = query('[data-result-details] pre');
		if (result) {
			try {
				const value = JSON.parse(result.textContent);
				if (Array.isArray(value.matched) && object(value.actions)) {
					const output = query('[data-result-summary]'); output.hidden = false;
					const heading = document.createElement('strong');
					heading.textContent = value.matched.length ? text('matches').replace('{count}', value.matched.length) : text('no_matches');
					const actions = document.createElement('p'); actions.textContent = actionsSummary(value.actions);
					output.append(heading, actions); query('[data-result-details]').open = false;
					query('[data-preview-panel]').open = true;
				}
			} catch { query('[data-result-details]').open = true; }
		}
	});
})();
