/** The backend supplies the only defaults, enum choices, ranges and units. */
export function defaults(schema) {
	return Object.fromEntries(Object.entries(schema).map(([key, spec]) => [key, spec.default]));
}

export function preferenceErrors(values, schema) {
	if (!values || typeof values !== 'object' || Array.isArray(values)) return ['preferences'];
	return Object.entries(values).filter(([key, value]) => {
		const spec = schema[key];
		return !spec || (spec.type === 'enum' && !spec.options.includes(value)) ||
			(spec.type === 'boolean' && typeof value !== 'boolean') ||
			(spec.type === 'integer' && (!Number.isInteger(value) || value < spec.min || value > spec.max));
	}).map(([key]) => key);
}

export function preferences(values, schema) {
	const result = defaults(schema);
	for (const key of Object.keys(schema)) {
		if (Object.hasOwn(values, key) && !preferenceErrors({ [key]: values[key] }, schema).length) result[key] = values[key];
	}
	return result;
}
