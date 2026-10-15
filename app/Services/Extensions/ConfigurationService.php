<?php
declare(strict_types=1);

namespace FreshRSS\Services\Extensions;

use FreshRSS_Context;
use JsonException;
use LogicException;
use Minz_Configuration;
use Minz_Extension;
use Minz_User;
use RuntimeException;
use Throwable;

/**
 * Declarative settings for trusted in-process extensions. This is validation, not a PHP sandbox.
 * @phpstan-type SettingSpec array{type:'boolean'|'integer'|'number'|'string'|'array'|'object',default?:mixed,required?:bool,nullable?:bool,secret?:bool,label?:string,description?:string,min?:int|float,max?:int|float,minLength?:int,maxLength?:int,maxItems?:int,maxBytes?:int,enum?:list<mixed>}
 * @phpstan-type SettingsSchema array<string,SettingSpec>
 * @phpstan-type SettingsDescription array{extension:string,version:string,schema:SettingsSchema,values:array<string,mixed>,
 *   errors:array<string,string>,redacted:list<string>}
 * @phpstan-type SettingsResult array{saved:bool,errors:array<string,string>,conflict?:SettingsDescription}
 * @phpstan-type SettingsChange array{extension:Minz_Extension,configuration:array<array-key,mixed>,expectedVersion?:string}
 * @phpstan-type SettingsBatchResult array{saved:bool,errors:array<string,array<string,string>>,
 *   conflicts:array<string,SettingsDescription>,extensions:array<string,SettingsDescription>}
 */
final class ConfigurationService {
	public const DEFAULT_IMPORT_BYTES = 1048576;
	public const MAX_VALUE_DEPTH = 32;
	public const DEFAULT_MAX_BATCH_EXTENSIONS = 100;

	/** Reject malformed declarations before accepting configuration.
	 * @param array<array-key,mixed> $schema
	 * @return array<string,string>
	 */
	public static function schemaErrors(array $schema): array {
		$errors = [];
		$allowed = ['type', 'default', 'required', 'nullable', 'secret', 'label', 'description',
			'min', 'max', 'minLength', 'maxLength', 'maxItems', 'maxBytes', 'enum'];
		foreach ($schema as $key => $spec) {
			if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $key) !== 1 || !is_array($spec) ||
				!in_array($spec['type'] ?? null, ['boolean', 'integer', 'number', 'string', 'array', 'object'], true)) {
				$errors[is_string($key) ? $key : '[' . $key . ']'] = 'Invalid setting name or type';
				continue;
			}
			foreach ($spec as $property => $value) {
				if (!in_array($property, $allowed, true) ||
					(in_array($property, ['required', 'nullable', 'secret'], true) && !is_bool($value)) ||
					(in_array($property, ['label', 'description'], true) && !is_string($value)) ||
					(in_array($property, ['min', 'max'], true) && ((!is_int($value) && !is_float($value)) || !is_finite((float)$value))) ||
					(in_array($property, ['minLength', 'maxLength', 'maxItems', 'maxBytes'], true) && (!is_int($value) || $value < 0)) ||
					($property === 'enum' && (!is_array($value) || !array_is_list($value) || $value === []))) {
					$errors[$key] = 'Invalid schema property: ' . $property;
				}
			}
			if (isset($errors[$key])) {
				continue;
			}
			/** @var SettingSpec $spec */
			if ((isset($spec['min']) || isset($spec['max'])) && !in_array($spec['type'], ['integer', 'number'], true) ||
				(isset($spec['minLength']) || isset($spec['maxLength'])) && $spec['type'] !== 'string' ||
				isset($spec['maxItems']) && !in_array($spec['type'], ['array', 'object'], true)) {
				$errors[$key] = 'Constraint does not apply to this setting type';
				continue;
			}
			if ((isset($spec['min'], $spec['max']) && $spec['min'] > $spec['max']) ||
				(isset($spec['minLength'], $spec['maxLength']) && $spec['minLength'] > $spec['maxLength'])) {
				$errors[$key] = 'Schema minimum exceeds maximum';
			} elseif (array_key_exists('default', $spec) && self::valueError($spec['default'], $spec) !== '') {
				$errors[$key] = 'Schema default does not satisfy its constraints';
			}
			if (isset($spec['enum'])) {
				$options = $spec['enum'];
				unset($spec['enum']);
				foreach ($options as $option) {
					if (self::valueError($option, $spec) !== '') {
						$errors[$key] = 'Schema option does not satisfy its constraints';
						break;
					}
				}
			}
		}
		return $errors;
	}

	/** Strict types; no casts, truncation, or unknown-field removal.
	 * @param SettingsSchema $schema
	 * @param array<array-key,mixed> $configuration
	 * @return array<string,string>
	 */
	public static function validate(array $schema, array $configuration): array {
		$errors = self::schemaErrors($schema);
		if ($errors !== []) {
			return $errors;
		}
		foreach ($configuration as $key => $value) {
			if (!is_string($key) || !isset($schema[$key])) {
				$errors[is_string($key) ? $key : '[' . $key . ']'] = 'Unknown setting';
				continue;
			}
			$error = self::valueError($value, $schema[$key]);
			if ($error !== '') {
				$errors[$key] = $error;
			}
		}
		foreach ($schema as $key => $spec) {
			if (($spec['required'] ?? false) && !array_key_exists($key, $configuration) && !array_key_exists('default', $spec)) {
				$errors[$key] = 'Required setting is missing';
			}
		}
		return $errors;
	}

	/** @param SettingSpec $spec */
	private static function valueError(mixed $value, array $spec): string {
		if ($value === null && ($spec['nullable'] ?? false)) {
			return '';
		}
		$valid = match ($spec['type']) {
			'boolean' => is_bool($value),
			'integer' => is_int($value),
			'number' => (is_int($value) || is_float($value)) && is_finite((float)$value),
			'string' => is_string($value) && mb_check_encoding($value, 'UTF-8'),
			'array' => is_array($value) && array_is_list($value),
			'object' => is_array($value) && ($value === [] || !array_is_list($value)),
		};
		if (!$valid || !self::isJsonValue($value)) {
			return 'Expected ' . $spec['type'];
		}
		if (isset($spec['enum']) && !in_array($value, $spec['enum'], true)) {
			return 'Value is not in the allowed options';
		}
		if ((is_int($value) || is_float($value)) &&
			((isset($spec['min']) && $value < $spec['min']) || (isset($spec['max']) && $value > $spec['max']))) {
			return 'Value is outside the allowed range';
		}
		if (is_string($value) && ((isset($spec['minLength']) && strlen($value) < $spec['minLength']) ||
			(isset($spec['maxLength']) && strlen($value) > $spec['maxLength']))) {
			return 'Text length is outside the allowed byte range';
		}
		if (is_array($value) && isset($spec['maxItems']) && count($value) > $spec['maxItems']) {
			return 'Collection contains too many items';
		}
		try {
			$json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, self::MAX_VALUE_DEPTH);
		} catch (JsonException) {
			return 'Value must be finite JSON data within the depth limit';
		}
		if (isset($spec['maxBytes']) && strlen($json) > $spec['maxBytes']) {
			return 'Encoded value exceeds the allowed byte size';
		}
		return '';
	}

	private static function isJsonValue(mixed $value, int $depth = 0): bool {
		if ($depth >= self::MAX_VALUE_DEPTH) {
			return false;
		}
		if (is_array($value)) {
			foreach ($value as $item) {
				if (!self::isJsonValue($item, $depth + 1)) {
					return false;
				}
			}
			return true;
		}
		return $value === null || is_bool($value) || is_int($value) ||
			(is_float($value) && is_finite($value)) || (is_string($value) && mb_check_encoding($value, 'UTF-8'));
	}

	/** @return SettingsDescription */
	public static function describe(Minz_Extension $extension): array {
		self::requireUserExtension($extension);
		return self::describeValues($extension, $extension->getSettingsConfiguration());
	}

	/** @param array<string,mixed> $configuration
	 * @return SettingsDescription
	 */
	private static function describeValues(Minz_Extension $extension, array $configuration): array {
		$schema = $extension->getConfigurationSchema();
		$errors = self::schemaErrors($schema);
		$version = self::settingsVersion($extension, $configuration);
		if ($errors !== []) {
			return ['extension' => $extension->getEntrypoint(), 'version' => $version, 'schema' => [], 'values' => [], 'errors' => $errors, 'redacted' => []];
		}
		$values = self::valuesWithDefaults($schema, $configuration);
		$errors = self::validate($schema, $values);
		$redacted = [];
		foreach ($schema as $key => &$spec) {
			if ($spec['secret'] ?? false) {
				unset($values[$key], $spec['default'], $spec['enum']);
				$redacted[] = $key;
				if (isset($errors[$key])) {
					$errors[$key] = 'Invalid secret setting';
				}
			}
		}
		return ['extension' => $extension->getEntrypoint(), 'version' => $version, 'schema' => $schema, 'values' => $values,
			'errors' => $errors, 'redacted' => $redacted];
	}

	/** Merge a partial update, preserving omitted secrets and undeclared legacy fields.
	 * @param array<array-key,mixed> $configuration
	 * @return SettingsResult
	 * @throws RuntimeException
	 */
	public static function save(Minz_Extension $extension, array $configuration, ?string $expectedVersion = null): array {
		$change = ['extension' => $extension, 'configuration' => $configuration];
		if ($expectedVersion !== null) {
			$change['expectedVersion'] = $expectedVersion;
		}
		$result = self::saveBatch([$change]);
		$id = $extension->getEntrypoint();
		$response = ['saved' => $result['saved'], 'errors' => $result['errors'][$id] ?? ($result['errors']['_batch'] ?? [])];
		if (isset($result['conflicts'][$id])) {
			$response['conflict'] = $result['conflicts'][$id];
		}
		return $response;
	}

	/** Validate every candidate against the latest file while holding one lock, then publish once.
	 * @param list<SettingsChange> $changes
	 * @return SettingsBatchResult
	 * @throws RuntimeException
	 */
	public static function saveBatch(array $changes, int $maximum = self::DEFAULT_MAX_BATCH_EXTENSIONS): array {
		$result = ['saved' => false, 'errors' => [], 'conflicts' => [], 'extensions' => []];
		if ($changes === [] || count($changes) > $maximum) {
			$result['errors']['_batch'] = ['_batch' => 'Expected a non-empty batch within the extension count limit'];
			return $result;
		}
		$names = [];
		$entrypoints = [];
		foreach ($changes as $change) {
			$extension = $change['extension'];
			self::requireUserExtension($extension);
			// Check version prerequisites before any data can be published.
			self::settingsVersion($extension, $extension->getSettingsConfiguration());
			$name = $extension->getName();
			$id = $extension->getEntrypoint();
			if (isset($names[$name]) || isset($entrypoints[$id])) {
				$result['errors'][$id] = ['_batch' => 'An extension can only occur once in a batch'];
				return $result;
			}
			$names[$name] = true;
			$entrypoints[$id] = true;
		}
		$abort = new RuntimeException('Extension batch validation failed');
		try {
			$saved = FreshRSS_Context::userConf()->mutate(static function (array $latest) use ($changes, &$result, $abort): array {
				$extensions = $latest['extensions'] ?? [];
				if (!is_array($extensions)) {
					$result['errors']['_batch'] = ['_configuration' => 'Stored extension configuration is invalid'];
					throw $abort;
				}
				foreach ($changes as $change) {
					$extension = $change['extension'];
					$name = $extension->getName();
					$id = $extension->getEntrypoint();
					$current = $extensions[$name] ?? [];
					if (!is_array($current) || !is_array_keys_string($current)) {
						$result['errors'][$id] = ['_configuration' => 'Stored extension configuration is invalid'];
						continue;
					}
					if (isset($change['expectedVersion']) && !hash_equals(self::settingsVersion($extension, $current), $change['expectedVersion'])) {
						$result['conflicts'][$id] = self::describeValues($extension, $current);
						continue;
					}
					$candidate = self::prepare($extension, $current, $change['configuration']);
					if ($candidate['errors'] !== []) {
						$result['errors'][$id] = $candidate['errors'];
						continue;
					}
					$extensions[$name] = array_replace($current, $candidate['values']);
				}
				if ($result['errors'] !== [] || $result['conflicts'] !== []) {
					throw $abort;
				}
				$latest['extensions'] = $extensions;
				return $latest;
			});
		} catch (Throwable $error) {
			if ($error === $abort) {
				return $result;
			}
			throw new RuntimeException('Extension configuration could not be saved', 0, $error);
		}
		if (!$saved) {
			throw new RuntimeException('Extension configuration could not be saved');
		}
		$result['saved'] = true;
		foreach ($changes as $change) {
			$extension = $change['extension'];
			$result['extensions'][$extension->getEntrypoint()] = self::describe($extension);
		}
		return $result;
	}

	/** @param array<string,mixed> $current
	 * @param array<array-key,mixed> $configuration
	 * @return array{values:array<string,mixed>,errors:array<string,string>}
	 */
	private static function prepare(Minz_Extension $extension, array $current, array $configuration): array {
		$schema = $extension->getConfigurationSchema();
		if ($schema === []) {
			return ['values' => [], 'errors' => ['_schema' => 'Extension does not declare configurable settings']];
		}
		$errors = self::schemaErrors($schema);
		if ($errors !== []) {
			return ['values' => [], 'errors' => $errors];
		}
		$candidate = self::valuesWithDefaults($schema, $current);
		foreach ($configuration as $key => $value) {
			if (!is_string($key)) {
				return ['values' => [], 'errors' => ['[' . $key . ']' => 'Unknown setting']];
			}
			$candidate[$key] = $value;
		}
		$errors = self::validate($schema, $candidate);
		if ($errors === []) {
			$errors = $extension->validateConfiguration($candidate);
		}
		return ['values' => $candidate, 'errors' => $errors];
	}

	/** A per-extension token prevents unrelated plugin updates from causing false conflicts.
	 * @param array<string,mixed> $configuration
	 * @throws LogicException
	 */
	private static function settingsVersion(Minz_Extension $extension, array $configuration): string {
		$salt = Minz_Configuration::get('system')->attributeString('salt');
		if ($salt === null || $salt === '') {
			throw new LogicException('Extension configuration versions require a non-empty system salt');
		}
		return hash_hmac('sha256', serialize([Minz_User::name(), $extension->getEntrypoint(), $extension->getVersion(),
			$extension->getConfigurationSchema(), $configuration]), $salt);
	}

	/** @return array{version:int,extension:string,configuration:array<string,mixed>,redacted:list<string>} */
	public static function export(Minz_Extension $extension): array {
		$description = self::describe($extension);
		return ['version' => 1, 'extension' => $description['extension'], 'configuration' => $description['values'],
			'redacted' => $description['redacted']];
	}

	/** @return SettingsResult */
	public static function import(Minz_Extension $extension, string $json, int $maxBytes = self::DEFAULT_IMPORT_BYTES, ?string $expectedVersion = null): array {
		if (strlen($json) > $maxBytes) {
			return ['saved' => false, 'errors' => ['_import' => 'Import exceeds the allowed byte size']];
		}
		try {
			$document = json_decode($json, true, self::MAX_VALUE_DEPTH, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return ['saved' => false, 'errors' => ['_import' => 'Invalid JSON import']];
		}
		if (!is_array($document) || ($document['version'] ?? null) !== 1 ||
			($document['extension'] ?? null) !== $extension->getEntrypoint() || !is_array($document['configuration'] ?? null) ||
			array_diff(array_keys($document), ['version', 'extension', 'configuration', 'redacted']) !== []) {
			return ['saved' => false, 'errors' => ['_import' => 'Invalid import version, extension, or configuration']];
		}
		return self::save($extension, $document['configuration'], $expectedVersion);
	}

	/** Decode ordinary HTML controls at the boundary; core validation still sees precise types.
	 * Blank secret controls are omitted, so submitting another field cannot erase a saved credential.
	 * @param SettingsSchema $schema
	 * @param array<array-key,mixed> $fields
	 * @return array{configuration:array<string,mixed>,errors:array<string,string>}
	 */
	public static function formValues(array $schema, array $fields): array {
		$values = [];
		$errors = [];
		foreach ($fields as $key => $raw) {
			if (!is_string($key) || !isset($schema[$key])) {
				$errors[is_string($key) ? $key : '[' . $key . ']'] = 'Unknown setting';
				continue;
			}
			if (!is_string($raw)) {
				$errors[$key] = 'Invalid form value';
				continue;
			}
			$spec = $schema[$key];
			if ($raw === '' && ($spec['secret'] ?? false)) {
				continue;
			}
			if ($raw === '' && ($spec['nullable'] ?? false)) {
				$values[$key] = null;
				continue;
			}
			if ($raw === '' && !($spec['required'] ?? false) && !array_key_exists('default', $spec)) {
				continue;
			}
			try {
				if ((isset($spec['enum']) && !($spec['secret'] ?? false)) || in_array($spec['type'], ['array', 'object'], true)) {
					$values[$key] = json_decode($raw, true, self::MAX_VALUE_DEPTH, JSON_THROW_ON_ERROR);
				} elseif ($spec['type'] === 'boolean') {
					if (!in_array($raw, ['true', 'false'], true)) {
						$errors[$key] = 'Expected true or false';
						continue;
					}
					$values[$key] = $raw === 'true';
				} elseif ($spec['type'] === 'integer') {
					$value = filter_var($raw, FILTER_VALIDATE_INT);
					if ($value === false) {
						$errors[$key] = 'Expected integer';
						continue;
					}
					$values[$key] = $value;
				} elseif ($spec['type'] === 'number') {
					$value = filter_var($raw, FILTER_VALIDATE_FLOAT);
					if ($value === false || !is_finite($value)) {
						$errors[$key] = 'Expected finite number';
						continue;
					}
					$values[$key] = $value;
				} else {
					$values[$key] = $raw;
				}
			} catch (JsonException) {
				$errors[$key] = 'Invalid JSON value';
			}
		}
		return ['configuration' => $values, 'errors' => $errors];
	}

	/** @param SettingsSchema $schema
	 * @param array<string,mixed> $configuration
	 * @return array<string,mixed>
	 */
	private static function valuesWithDefaults(array $schema, array $configuration): array {
		$values = [];
		foreach ($schema as $key => $spec) {
			if (array_key_exists($key, $configuration)) {
				$values[$key] = $configuration[$key];
			} elseif (array_key_exists('default', $spec)) {
				$values[$key] = $spec['default'];
			}
		}
		return $values;
	}

	/** @throws LogicException */
	private static function requireUserExtension(Minz_Extension $extension): void {
		if ($extension->getType() !== 'user' || !FreshRSS_Context::hasUserConf()) {
			throw new LogicException('Settings service requires a user extension and a current user configuration');
		}
	}
}
