<?php
declare(strict_types=1);

/**
 * An extension manager to load extensions present in CORE_EXTENSIONS_PATH and THIRDPARTY_EXTENSIONS_PATH.
 *
 * @todo see coding style for methods!!
 * @phpstan-import-type ExtensionMetadata from Minz_Extension
 */
final class Minz_ExtensionManager {
	public const MAX_HOOK_ERROR_BYTES = 1024;

	private static string $ext_metaname = 'metadata.json';
	private static string $ext_entry_point = 'extension.php';
	/** @var array<string,Minz_Extension> */
	private static array $ext_list = [];
	/** @var array<string,Minz_Extension> */
	private static array $ext_list_enabled = [];
	/** @var array<string,bool> */
	private static array $ext_auto_enabled = [];
	/** @var array<string,string> Failures in this request; no persistent disable of user preferences. */
	private static array $load_errors = [];
	/** @var array<string,bool> */
	private static array $hooks_sorted = [];
	private static bool $observe_hooks = false;
	/** @var array<string,string> First optional presentation error per hook and request. */
	private static array $hook_errors = [];
	/** @var array<string,array{calls:int,failures:int,duration_ns:int|float}> One aggregate per enum hook, never arguments or content. */
	private static array $hook_metrics = [];
	/** @var array<string,bool> */
	private static array $enabling = [];

	/**
	 * List of available hooks. Please keep this list sorted.
	 * @var array<value-of<Minz_HookType>,array{'list':list<Minz_Hook>,'signature':Minz_HookSignature}>
	 */
	private static array $hook_list = [];

	/** Remove extensions and hooks from a previous initialisation */
	private static function reset(): void {
		$hadAny = !empty(self::$ext_list_enabled);
		foreach (self::$ext_list_enabled as $ext) {
			if (is_callable([$ext, 'autoload'])) {
				spl_autoload_unregister([$ext, 'autoload']);
			}
			$ext->disable();
		}
		self::$load_errors = [];
		self::$enabling = [];
		self::$hook_metrics = [];
		self::$hook_errors = [];
		self::$observe_hooks = false;
		self::$hooks_sorted = [];
		self::$ext_list = [];
		self::$ext_list_enabled = [];
		self::$ext_auto_enabled = [];
		foreach (Minz_HookType::cases() as $hook_type) {
			$hadAny |= !empty(self::$hook_list[$hook_type->value]['list']);
			self::$hook_list[$hook_type->value] = [
				'list' => [],
				'signature' => $hook_type->signature(),
			];
		}
		if ($hadAny) {
			gc_collect_cycles();
		}
	}

	/**
	 * Initialize the extension manager by loading extensions in EXTENSIONS_PATH.
	 *
	 * A valid extension is a directory containing metadata.json and
	 * extension.php files.
	 * metadata.json is a JSON structure where the only required fields are
	 * `name` and `entry_point`.
	 * extension.php should contain at least a class named <name>Extension where
	 * <name> must match with the entry point in metadata.json. This class must
	 * inherit from Minz_Extension class.
	 * @throws Minz_ConfigurationNamespaceException
	 */
	public static function init(): void {
		self::reset();

		$list_core_extensions = array_diff(scandir(CORE_EXTENSIONS_PATH) ?: [], [ '..', '.' ]);
		$list_thirdparty_extensions = array_diff(scandir(THIRDPARTY_EXTENSIONS_PATH) ?: [], [ '..', '.' ], $list_core_extensions);
		array_walk($list_core_extensions, function (&$s) { $s = CORE_EXTENSIONS_PATH . '/' . $s; });
		array_walk($list_thirdparty_extensions, function (&$s) { $s = THIRDPARTY_EXTENSIONS_PATH . '/' . $s; });

		$list_potential_extensions = array_merge($list_core_extensions, $list_thirdparty_extensions);

		$system_conf = Minz_Configuration::get('system');
		self::$ext_auto_enabled = array_filter(
			$system_conf->attributeArray('extensions_enabled') ?? [],
			fn($value, $key): bool => is_string($key) && is_bool($value),
			ARRAY_FILTER_USE_BOTH);

		foreach ($list_potential_extensions as $ext_pathname) {
			if (!is_dir($ext_pathname)) {
				continue;
			}
			$metadata_filename = $ext_pathname . '/' . self::$ext_metaname;

			// Try to load metadata file.
			if (!file_exists($metadata_filename)) {
				// No metadata file? Invalid!
				continue;
			}
			$meta_raw_content = file_get_contents($metadata_filename) ?: '';
			/** @var ExtensionMetadata|null $meta_json */
			$meta_json = json_decode($meta_raw_content, true);
			if (!is_array($meta_json) || !self::isValidMetadata($meta_json)) {
				// metadata.json is not a json file? Invalid!
				// or metadata.json is invalid (no required information), invalid!
				self::loadError(basename($ext_pathname), 'Invalid metadata.json');
				continue;
			}

			$meta_json['path'] = $ext_pathname;
			$error = self::compatibilityError($meta_json);
			if ($error !== '') {
				self::loadError($meta_json['name'], $error);
				continue;
			}

			// Try to load extension itself
			$extension = self::load($meta_json);
			if ($extension != null) {
				self::register($extension);
			}
		}
		self::enableByList(self::$ext_auto_enabled, 'system');
	}

	/**
	 * Indicates if the given parameter is a valid metadata array.
	 *
	 * Required fields are:
	 * - `name`: the name of the extension
	 * - `entry_point`: a class name to load the extension source code
	 * If the extension class name is `TestExtension`, entry point will be `Test`.
	 * `entry_point` must be composed of alphanumeric characters.
	 *
	 * @param ExtensionMetadata $meta is an array of values.
	 * @return bool true if the array is valid, false else.
	 */
	private static function isValidMetadata(array $meta): bool {
		if (!is_string($meta['name'] ?? null) || trim($meta['name']) === '' ||
			!is_string($meta['entrypoint'] ?? null) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $meta['entrypoint']) !== 1) {
			return false;
		}
		foreach (['author', 'description', 'min_php', 'min_freshrss', 'max_freshrss'] as $key) {
			if (isset($meta[$key]) && !is_string($meta[$key])) {
				return false;
			}
		}
		if (isset($meta['requires'])) {
			if (!is_array($meta['requires'])) {
				return false;
			}
			foreach ($meta['requires'] as $name => $version) {
				if (!is_string($name) || trim($name) === '' || !is_string($version) ||
					($version !== '*' && preg_match('/^[0-9]+(?:[.][0-9A-Za-z-]+)*$/D', $version) !== 1)) {
					return false;
				}
			}
		}
		foreach (['conflicts', 'capabilities'] as $key) {
			if (!isset($meta[$key])) {
				continue;
			}
			if (!is_array($meta[$key]) || !array_is_list($meta[$key])) {
				return false;
			}
			foreach ($meta[$key] as $value) {
				if (!is_string($value) || trim($value) === '') {
					return false;
				}
			}
		}
		return (!isset($meta['configure_assets']) || is_bool($meta['configure_assets'])) &&
			(!isset($meta['type']) || in_array($meta['type'], ['system', 'user'], true)) &&
			(!isset($meta['version']) || is_scalar($meta['version']));
	}

	/** @param ExtensionMetadata $meta */
	private static function compatibilityError(array $meta): string {
		foreach (['min_php' => [PHP_VERSION, '>='], 'min_freshrss' => [FRESHRSS_VERSION, '>='],
			'max_freshrss' => [FRESHRSS_VERSION, '<=']] as $key => [$current, $operator]) {
			if (!empty($meta[$key]) && !version_compare($current, $meta[$key], $operator)) {
				return $key . ': ' . $meta[$key] . ' (current: ' . $current . ')';
			}
		}
		return '';
	}

	private static function loadError(string $name, string $message): void {
		self::$load_errors[$name] = $message;
		Minz_Log::warning('Extension ' . $name . ': ' . $message);
	}

	/** @return array<string,string> */
	public static function getLoadErrors(): array {
		return self::$load_errors;
	}

	/**
	 * Load the extension source code based on info metadata.
	 *
	 * @param ExtensionMetadata $info an array containing information about extension.
	 * @return Minz_Extension|null an extension inheriting from Minz_Extension.
	 */
	private static function load(array $info): ?Minz_Extension {
		$entry_point_filename = $info['path'] . '/' . self::$ext_entry_point;
		$ext_class_name = $info['entrypoint'] . 'Extension';

		if (!is_readable($entry_point_filename)) {
			self::loadError($info['name'], 'Missing or unreadable extension.php');
			return null;
		}
		if (class_exists($ext_class_name, false) &&
			(new ReflectionClass($ext_class_name))->getFileName() !== realpath($entry_point_filename)) {
			self::loadError($info['name'], 'Duplicate entrypoint: ' . $info['entrypoint']);
			return null;
		}
		try {
			include_once $entry_point_filename;
			if (!class_exists($ext_class_name, false) || !is_subclass_of($ext_class_name, Minz_Extension::class)) {
				self::loadError($info['name'], $ext_class_name . ' must extend Minz_Extension');
				return null;
			}
			$extension = new $ext_class_name($info);
		} catch (Throwable $e) {
			self::loadError($info['name'], $e->getMessage());
			return null;
		}

		return $extension;
	}

	/**
	 * Add the extension to the list of the known extensions ($ext_list).
	 *
	 * If the extension is present in $ext_auto_enabled and if its type is "system",
	 * it will be enabled at the same time.
	 *
	 * @param Minz_Extension $ext a valid extension.
	 */
	private static function register(Minz_Extension $ext): void {
		$name = $ext->getName();
		if (isset(self::$ext_list[$name])) {
			self::loadError($name, 'Duplicate extension name: ' . basename($ext->getPath()));
			return;
		}
		self::$ext_list[$name] = $ext;
	}

	/** Run installation and initialisation before the controller records an enabled preference.
	 * Installation side effects belong to the extension; only manager-owned hooks are restored on failure.
	 * @return true|string
	 */
	public static function installAndEnable(string $name): bool|string {
		$extension = self::findExtension($name);
		if ($extension === null) {
			return 'Unknown extension: ' . $name;
		}
		if ($extension->isEnabled()) {
			return true;
		}
		$error = self::getActivationError($name);
		if ($error !== '') {
			return $error;
		}
		$previousHooks = self::$hook_list;
		$previousSorted = self::$hooks_sorted;
		try {
			$result = $extension->install();
			if ($result !== true) {
				self::$hook_list = $previousHooks;
				self::$hooks_sorted = $previousSorted;
				return $result;
			}
			self::enableByList([$name => true], $extension->getType());
			if (!isset(self::$ext_list_enabled[$name])) {
				self::$hook_list = $previousHooks;
				self::$hooks_sorted = $previousSorted;
				return self::$load_errors[$name] ?? 'Extension initialisation failed';
			}
		} catch (Throwable $error) {
			self::$hook_list = $previousHooks;
			self::$hooks_sorted = $previousSorted;
			self::loadError($name, $error->getMessage());
			return $error->getMessage();
		}
		return true;
	}

	/**
	 * Enable an extension so it will be called when necessary.
	 *
	 * The extension init() method will be called.
	 *
	 * @param string $ext_name is the name of a valid extension present in $ext_list.
	 * @param array<string,bool> $requested
	 * @param 'system'|'user'|null $onlyOfType only enable if the extension matches that type. Set to null to load all.
	 */
	private static function enable(string $ext_name, ?string $onlyOfType = null, array $requested = []): void {
		if (isset(self::$ext_list[$ext_name]) && !isset(self::$ext_list_enabled[$ext_name])) {
			$ext = self::$ext_list[$ext_name];

			if ($onlyOfType !== null && $ext->getType() !== $onlyOfType) {
				// Do not enable an extension of the wrong type
				return;
			}

			if (isset(self::$enabling[$ext_name])) {
				self::loadError($ext_name, 'Cyclic extension dependency');
				return;
			}
			$error = self::getActivationError($ext_name, $requested);
			if ($error !== '') {
				self::loadError($ext_name, $error);
				return;
			}
			unset(self::$load_errors[$ext_name]);
			self::$enabling[$ext_name] = true;
			foreach ($ext->getRequirements() as $dependency => $version) {
				self::enable($dependency, $onlyOfType, $requested);
				if (!self::isExtensionEnabled($dependency)) {
					unset(self::$enabling[$ext_name]);
					if (!isset(self::$load_errors[$ext_name])) {
						self::loadError($ext_name, 'Dependency failed to initialise: ' . $dependency);
					}
					return;
				}
			}
			unset(self::$enabling[$ext_name]);
			unset(self::$load_errors[$ext_name]);
			self::$ext_list_enabled[$ext_name] = $ext;

			$previousHooks = self::$hook_list;
			if (is_callable([$ext, 'autoload'])) {
				spl_autoload_register([$ext, 'autoload']);
			}
			$ext->enable();
			try {
				$ext->init();
			} catch (Throwable $e) {
				self::$hook_list = $previousHooks;
				self::$hooks_sorted = [];
				if (is_callable([$ext, 'autoload'])) {
					spl_autoload_unregister([$ext, 'autoload']);
				}
				self::loadError($ext->getName(), $e->getMessage());
				$ext->disable();
				unset(self::$ext_list_enabled[$ext_name]);
			}
		}
	}

	/**
	 * Enable a list of extensions.
	 *
	 * @param array<string,bool> $ext_list the names of extensions we want to load.
	 * @param 'system'|'user'|null $onlyOfType limit the extensions to load to those of those type. Set to null string to load all.
	 */
	public static function enableByList(?array $ext_list, ?string $onlyOfType = null): void {
		if (empty($ext_list)) {
			return;
		}
		$requested = [];
		foreach ($ext_list as $ext_name => $ext_status) {
			if ($ext_status && is_string($ext_name) && isset(self::$ext_list[$ext_name]) &&
				($onlyOfType === null || self::$ext_list[$ext_name]->getType() === $onlyOfType)) {
				$requested[$ext_name] = true;
			}
		}
		foreach ($requested as $ext_name => $ext_status) {
			self::enable($ext_name, $onlyOfType, $requested);
		}
	}

	/**
	 * Explain why an extension cannot be enabled. Dependencies must be explicitly selected or already enabled.
	 * @param array<string,bool> $requested
	 */
	public static function getActivationError(string $name, array $requested = []): string {
		$extension = self::findExtension($name);
		if ($extension === null) {
			return 'Unknown extension: ' . $name;
		}
		foreach ($extension->getRequirements() as $dependency => $version) {
			$required = self::findExtension($dependency);
			if ($required === null) {
				return 'Missing dependency: ' . $dependency;
			}
			if ($extension->getType() === 'system' && $required->getType() !== 'system') {
				return 'System extension requires user extension: ' . $dependency;
			}
			if ($version !== '*' && !version_compare($required->getVersion(), $version, '>=')) {
				return 'Dependency requires version ' . $version . ': ' . $dependency;
			}
			if (!self::isExtensionEnabled($dependency) && empty($requested[$dependency])) {
				return 'Dependency is disabled: ' . $dependency;
			}
		}
		foreach (self::$ext_list as $otherName => $other) {
			if ($otherName !== $name && (self::isExtensionEnabled($otherName) || !empty($requested[$otherName])) &&
				(in_array($otherName, $extension->getConflicts(), true) || in_array($name, $other->getConflicts(), true))) {
				return 'Conflicting extension: ' . $otherName;
			}
		}
		return '';
	}

	/** @return list<string> Enabled dependants which prevent safe deactivation/removal. */
	public static function getDependants(string $name): array {
		$result = [];
		foreach (self::$ext_list_enabled as $extension) {
			if (array_key_exists($name, $extension->getRequirements())) {
				$result[] = $extension->getName();
			}
		}
		return $result;
	}

	/** @return array<string,string> */
	public static function getHookErrors(): array {
		return self::$hook_errors;
	}

	/** Request-local, bounded aggregates. Disabled by default; no hook payload is retained. */
	public static function observeHooks(bool $enabled): void {
		self::$observe_hooks = $enabled;
		self::$hook_metrics = [];
	}

	/** @return array<string,array{calls:int,failures:int,duration_ns:int|float}> */
	public static function getHookMetrics(): array {
		return self::$hook_metrics;
	}

	/** @param array<int|string,mixed> $args */
	private static function invokeHook(Minz_HookType $type, Minz_Hook $hook, array $args = []): mixed {
		$start = self::$observe_hooks ? hrtime(true) : 0;
		$failed = false;
		try {
			return ($hook->getFunction())(...$args);
		} catch (Throwable $error) {
			$failed = true;
			// Only optional HTML additions are isolated. Mutating/filtering hooks preserve failure semantics.
			if ($type->signature() !== Minz_HookSignature::NoneToString) {
				throw $error;
			}
			if (!isset(self::$hook_errors[$type->value])) {
				self::$hook_errors[$type->value] = substr($error->getMessage(), 0, self::MAX_HOOK_ERROR_BYTES);
				Minz_Log::warning('Extension hook ' . $type->value . ': ' . self::$hook_errors[$type->value]);
			}
			return null;
		} finally {
			if ($start !== 0) {
				$metrics = self::$hook_metrics[$type->value] ?? ['calls' => 0, 'failures' => 0, 'duration_ns' => 0];
				$metrics['calls']++;
				$metrics['failures'] += (int)$failed;
				$metrics['duration_ns'] += hrtime(true) - $start;
				self::$hook_metrics[$type->value] = $metrics;
			}
		}
	}

	/**
	 * Return a list of extensions.
	 *
	 * @param bool $only_enabled if true returns only the enabled extensions (false by default).
	 * @return Minz_Extension[] an array of extensions.
	 */
	public static function listExtensions(bool $only_enabled = false): array {
		if ($only_enabled) {
			return self::$ext_list_enabled;
		} else {
			return self::$ext_list;
		}
	}

	/**
	 * Return an extension by its name.
	 *
	 * @param string $ext_name the name of the extension.
	 * @return Minz_Extension|null the corresponding extension or null if it doesn't exist.
	 */
	public static function findExtension(string $ext_name): ?Minz_Extension {
		if (!isset(self::$ext_list[$ext_name])) {
			return null;
		}

		return self::$ext_list[$ext_name];
	}

	/**
	 * Add a hook function to a given hook.
	 *
	 * The hook name must be a valid one. For the valid list, see Minz_HookType enum.
	 *
	 * @param string|Minz_HookType $hook_type the hook name (must exist).
	 * @param callable $hook_function the function name to call (must be callable).
	 * @param int $priority the priority of the hook, default priority is 0, the higher the value the lower the priority
	 */
	public static function addHook(string|Minz_HookType $hook_type, $hook_function, int $priority = Minz_Hook::DEFAULT_PRIORITY): void {
		if (null === $hook_type = self::extractHookType($hook_type)) {
			return;
		}
		$hook_type_name = $hook_type->value;

		if (is_callable($hook_function)) {
			self::$hooks_sorted[$hook_type_name] = false;
			self::$hook_list[$hook_type_name]['list'][] = new Minz_Hook(\Closure::fromCallable($hook_function), $priority);
		}
	}

	/**
	 * @param string|Minz_HookType $hook_type the hook type or its name
	 * @return Minz_HookType|null
	 */
	private static function extractHookType(string|Minz_HookType $hook_type) {
		if ($hook_type instanceof Minz_HookType) {
			return $hook_type;
		}

		return Minz_HookType::tryFrom($hook_type);
	}

	/**
	 * @param Minz_HookType $hook_type the hook type or its name
	 * @return list<Minz_Hook>
	 */
	private static function retrieveHookList(Minz_HookType $hook_type): array {
		$name = $hook_type->value;
		if (empty(self::$hook_list[$name]['list'])) {
			return [];
		}
		if (empty(self::$hooks_sorted[$name])) {
			usort(self::$hook_list[$name]['list'], static fn (Minz_Hook $a, Minz_Hook $b): int => $a->getPriority() <=> $b->getPriority());
			self::$hooks_sorted[$name] = true;
		}
		return self::$hook_list[$name]['list'];
	}

	/**
	 * Call functions related to a given hook.
	 *
	 * The hook name must be a valid one. For the valid list, see Minz_HookType enum.
	 *
	 * @param string|Minz_HookType $hook_type the hook to call.
	 * @param mixed ...$args additional parameters (for signature, please see Minz_HookType enum).
	 * @return mixed|void|null final result of the called hook.
	 */
	public static function callHook(string|Minz_HookType $hook_type, ...$args) {
		if (null === $hook_type = self::extractHookType($hook_type)) {
			return;
		}

		$signature = $hook_type->signature();
		if ($signature === Minz_HookSignature::OneToOne) {
			return self::callOneToOne($hook_type, $args[0] ?? null);
		} elseif ($signature === Minz_HookSignature::PassArguments) {
			foreach (self::retrieveHookList($hook_type) as $hook) {
				$result = self::invokeHook($hook_type, $hook, $args);
				if ($result !== null) {
					return $result;
				}
			}
		} elseif ($signature === Minz_HookSignature::NoneToString) {
			return self::callHookString($hook_type);
		} elseif ($signature === Minz_HookSignature::NoneToNone) {
			self::callHookVoid($hook_type);
		}
		return;
	}

	/**
	 * Call a hook which takes one argument and return a result.
	 *
	 * The result is chained between the extension, for instance, first extension
	 * hook will receive the initial argument and return a result which will be
	 * passed as an argument to the next extension hook and so on.
	 *
	 * If a hook return a null or false value, the method is stopped and that value is returned.
	 *
	 * @param Minz_HookType $hook_type is the hook type to call.
	 * @param mixed $arg is the argument to pass to the first extension hook.
	 * @return mixed|null final chained result of the hooks. If nothing is changed,
	 *         the initial argument is returned.
	 */
	private static function callOneToOne(Minz_HookType $hook_type, mixed $arg): mixed {
		$result = $arg;
		foreach (self::retrieveHookList($hook_type) as $hook) {
			$result = self::invokeHook($hook_type, $hook, [$arg]);

			if ($result === null || $result === false) {
				break;
			}

			$arg = $result;
		}
		return $result;
	}

	/**
	 * Call a hook which takes no argument and returns a string.
	 *
	 * The result is concatenated between each hook and the final string is
	 * returned.
	 *
	 * @param string|Minz_HookType $hook_type is the hook to call.
	 * @return string concatenated result of the call to all the hooks.
	 */
	public static function callHookString(string|Minz_HookType $hook_type): string {
		if (null === $hook_type = self::extractHookType($hook_type)) {
			return '';
		}

		$result = '';
		foreach (self::retrieveHookList($hook_type) as $hook) {
			$return = self::invokeHook($hook_type, $hook);
			if (is_scalar($return)) {
				$result .= $return;
			}
		}
		return $result;
	}

	/**
	 * Call a hook which takes no argument and returns nothing.
	 *
	 * This case is simpler than callOneToOne because hooks are called one by
	 * one, without any consideration of argument nor result.
	 *
	 * @param string|Minz_HookType $hook_type is the hook to call.
	 */
	public static function callHookVoid(string|Minz_HookType $hook_type): void {
		if (null === $hook_type = self::extractHookType($hook_type)) {
			return;
		}

		foreach (self::retrieveHookList($hook_type) as $hook) {
			self::invokeHook($hook_type, $hook);
		}
	}

	/**
	 * Call a hook which takes no argument and returns nothing.
	 * Same as callHookVoid but only calls the first extension.
	 *
	 * @param string|Minz_HookType $hook_type is the hook to call.
	 */
	public static function callHookUnique(string|Minz_HookType $hook_type): bool {
		if (null === $hook_type = self::extractHookType($hook_type)) {
			throw new \RuntimeException("The “{$hook_type}” does not exist!");
		}

		foreach (self::retrieveHookList($hook_type) as $hook) {
			self::invokeHook($hook_type, $hook);
			return true;
		}
		return false;
	}

	/**
	 * Check if a extension is enabled
	 *
	 * @param string $ext_name is the extension's name as provided in metadata.json
	 */
	public static function isExtensionEnabled(string $ext_name): bool {
		return isset(self::$ext_list_enabled[$ext_name]);
	}
}
