<?php
declare(strict_types=1);

use FreshRSS\Services\Extensions\ConfigurationService as ExtensionsConfigurationService;

/**
 * The extension base class.
 * @phpstan-import-type SettingsSchema from ExtensionsConfigurationService
 *
 * @phpstan-type ExtensionMetadata array{name:string,entrypoint:string,author?:string,description?:string,version?:string,type?:'system'|'user',path:string,min_php?:string,min_freshrss?:string,max_freshrss?:string,configure_assets?:bool,requires?:array<string,string>,conflicts?:list<string>,capabilities?:list<string>}
 */
abstract class Minz_Extension {
	private readonly string $name;
	private readonly string $entrypoint;
	private readonly string $path;
	private readonly bool $configure_assets;
	/** @var array<string,string> */
	private readonly array $requires;
	/** @var list<string> */
	private readonly array $conflicts;
	/** @var list<string> Informational declarations, not a PHP sandbox. */
	private readonly array $capabilities;
	private string $author;
	private string $description;
	private string $version;
	/** @var 'system'|'user' */
	private string $type;

	/** @var array{0:'system',1:'user'} */
	public static array $authorized_types = [
		'system',
		'user',
	];

	private bool $is_enabled;

	/** @var array<string,string> */
	protected array $csp_policies = [];

	/**
	 * The constructor to assign specific information to the extension.
	 *
	 * Available fields are:
	 * - name: the name of the extension (required).
	 * - entrypoint: the extension class name (required).
	 * - path: the pathname to the extension files (required).
	 * - author: the name and / or email address of the extension author.
	 * - description: a short description to describe the extension role.
	 * - version: a version for the current extension.
	 * - type: "system" or "user" (default).
	 *
	 * @param ExtensionMetadata $meta_info
	 * contains information about the extension.
	 */
	final public function __construct(array $meta_info) {
		if (!is_string($meta_info['name'] ?? null) || $meta_info['name'] === '') {
			throw new Minz_ExtensionException('Invalid `name` info!');
		}
		$this->name = $meta_info['name'];

		if (!is_string($meta_info['entrypoint'] ?? null) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $meta_info['entrypoint']) !== 1) {
			throw new Minz_ExtensionException('Invalid `entrypoint` info!', $this->name);
		}
		$this->entrypoint = $meta_info['entrypoint'];

		if (!is_string($meta_info['path'] ?? null) || $meta_info['path'] === '') {
			throw new Minz_ExtensionException('Invalid `path` info!', $this->name);
		}
		$this->path = $meta_info['path'];
		$this->configure_assets = $meta_info['configure_assets'] ?? false;
		$this->requires = $meta_info['requires'] ?? [];
		$this->conflicts = $meta_info['conflicts'] ?? [];
		$this->capabilities = $meta_info['capabilities'] ?? [];

		$this->author = isset($meta_info['author']) ? $meta_info['author'] : '';
		$this->description = isset($meta_info['description']) ? $meta_info['description'] : '';
		$this->version = isset($meta_info['version']) ? (string)$meta_info['version'] : '0.1';
		$this->setType(isset($meta_info['type']) ? $meta_info['type'] : 'user');

		$this->is_enabled = false;
	}

	/**
	 * Used when installing an extension (e.g. update the database scheme).
	 * @throws Throwable
	 *
	 * @return string|true true if the extension has been installed or a string explaining the problem.
	 */
	public function install() {
		return true;
	}

	/**
	 * Used when uninstalling an extension (e.g. revert the database scheme to
	 * cancel changes from install).
	 * @throws Throwable
	 *
	 * @return string|true true if the extension has been uninstalled or a string explaining the problem.
	 */
	public function uninstall() {
		return true;
	}

	/**
	 * Call at the initialization of the extension (i.e. when the extension is
	 * enabled by the extension manager).
	 * @throws Throwable Extensions can fail with runtime errors as well as framework exceptions.
	 * @return void
	 */
	public function init() {
		$this->migrateExtensionUserPath();
	}

	/**
	 * Set the current extension to enable.
	 */
	final public function enable(): void {
		$this->is_enabled = true;
	}

	final public function disable(): void {
		$this->is_enabled = false;
	}

	/**
	 * Return if the extension is currently enabled.
	 *
	 * @return bool true if extension is enabled, false otherwise.
	 */
	final public function isEnabled(): bool {
		return $this->is_enabled;
	}

	/**
	 * Return the content of the configure view for the current extension.
	 *
	 * @return string|false html content from ext_dir/configure.phtml, false if it does not exist.
	 */
	final public function getConfigureView(): string|false {
		$filename = $this->path . '/configure.phtml';
		if (!file_exists($filename)) {
			return false;
		}

		ob_start();
		try {
			include $filename;
		} catch (Throwable $error) {
			ob_end_clean();
			throw $error;
		}
		$html = ob_get_clean();
		if ($html === false) {
			return false;
		}
		// Convention-based configuration assets also work inside the asynchronous settings panel.
		if ($this->configure_assets && is_file($this->path . '/static/configure.css')) {
			$html .= '<link rel="stylesheet" href="' . $this->getFileUrl('configure.css') . '" />';
		}
		if ($this->configure_assets && is_file($this->path . '/static/configure.js')) {
			$html .= '<script data-extension-configure src="' . $this->getFileUrl('configure.js') . '" defer></script>';
		}
		return $html;
	}

	/**
	 * Handle the configure action.
	 * @throws Throwable
	 * @return void
	 */
	public function handleConfigureAction() {
		$this->migrateExtensionUserPath();
	}

	/**
	 * @return non-empty-string
	 */
	final public function getName(): string {
		return $this->name;
	}
	/**
	 * @return non-empty-string
	 */
	final public function getEntrypoint(): string {
		return $this->entrypoint;
	}
	/**
	 * @return non-empty-string
	 */
	final public function getPath(): string {
		return $this->path;
	}
	final public function getAuthor(): string {
		return $this->author;
	}
	final public function getDescription(): string {
		return $this->description;
	}
	final public function getVersion(): string {
		return $this->version;
	}
	/** Optional declarative settings. Existing extensions need not implement this interface.
	 * @return SettingsSchema
	 */
	public function getConfigurationSchema(): array {
		return [];
	}

	/** Additional extension-specific validation after type and range checks.
	 * @param array<string,mixed> $configuration
	 * @return array<string,string> Setting names mapped to errors, without secret values.
	 */
	public function validateConfiguration(array $configuration): array {
		return [];
	}

	/** Current user's stored values; the public settings service removes secrets on export.
	 * @return array<string,mixed>
	 */
	final public function getSettingsConfiguration(): array {
		return $this->configuration('user');
	}

	/** Used by the settings service after validation. PHP extensions are trusted code.
	 * @param array<string,mixed> $configuration
	 * @throws Minz_ExtensionException
	 */
	final public function saveSettingsConfiguration(array $configuration): void {
		if ($this->getType() !== 'user') {
			throw new Minz_ExtensionException('User settings cannot modify a system extension', $this->name);
		}
		$this->setConfiguration('user', $configuration);
	}

	/** @return array<string,string> Required extension names and minimum versions (or *). */
	final public function getRequirements(): array {
		return $this->requires;
	}
	/** @return list<string> */
	final public function getConflicts(): array {
		return $this->conflicts;
	}
	/** @return list<string> Informational declarations only. */
	final public function getCapabilities(): array {
		return $this->capabilities;
	}
	/** @return 'system'|'user' */
	final public function getType(): string {
		return $this->type;
	}

	/** @param 'user'|'system' $type */
	private function setType(string $type): void {
		if (!in_array($type, ['user', 'system'], true)) {
			throw new Minz_ExtensionException('invalid `type` info', $this->name);
		}
		$this->type = $type;
	}

	/** Return the user-specific, extension-specific, folder where this extension can save user-specific data */
	final protected function getExtensionUserPath(): string {
		$username = Minz_User::name() ?: '_';
		return USERS_PATH . "/{$username}/extensions/{$this->getEntrypoint()}";
	}

	private function migrateExtensionUserPath(): void {
		$username = Minz_User::name() ?: '_';
		$old_extension_user_path = USERS_PATH . "/{$username}/extensions/{$this->getName()}";
		$new_extension_user_path = $this->getExtensionUserPath();
		if (!str_contains($this->getName(), '/') && !str_contains($this->getName(), '\\') &&
			!in_array($this->getName(), ['.', '..'], true) && is_dir($old_extension_user_path) && !file_exists($new_extension_user_path)) {
			rename($old_extension_user_path, $new_extension_user_path);
		}
	}

	private static function isSafeFilename(string $filename): bool {
		return $filename !== '' && preg_match('~[\\\\\x00-\x1f\x7f]~', $filename) !== 1 &&
			!str_starts_with($filename, '/') && !in_array('..', explode('/', $filename), true) &&
			!in_array('.', explode('/', $filename), true);
	}

	private function resolveUserFile(string $filename): ?string {
		if (!self::isSafeFilename($filename)) {
			return null;
		}
		$base = realpath($this->getExtensionUserPath());
		$resolved = realpath($this->getExtensionUserPath() . '/' . $filename);
		return $base !== false && $resolved !== false && is_file($resolved) &&
			str_starts_with($resolved, $base . DIRECTORY_SEPARATOR) ? $resolved : null;
	}

	/** Return whether a user-specific, extension-specific, file exists */
	final public function hasFile(string $filename): bool {
		if (!self::isSafeFilename($filename)) {
			return false;
		}
		return $this->resolveUserFile($filename) !== null;
	}

	/** Return the motification time of the user-specific, extension-specific, file or null if it does not exist */
	final public function mtimeFile(string $filename): ?int {
		if (!$this->hasFile($filename)) {
			return null;
		}
		return @filemtime($this->getExtensionUserPath() . '/' . $filename) ?: null;
	}

	/** Return the user-specific, extension-specific, file content or null if it does not exist */
	final public function getFile(string $filename): ?string {
		if (!$this->hasFile($filename)) {
			return null;
		}
		$content = @file_get_contents($this->getExtensionUserPath() . '/' . $filename);
		return is_string($content) ? $content : null;
	}

	/**
	 * Return the url for a given file.
	 *
	 * @param string $filename name of the file to serve.
	 * @param '' $type MIME type of the file to serve. Deprecated: always use the file extension.
	 * @param bool $isStatic indicates if the file is a static file or a user file. Default is static.
	 * @return string url corresponding to the file.
	 */
	final public function getFileUrl(string $filename, string $type = '', bool $isStatic = true): string {
		if ($isStatic) {
			$dir = basename($this->path);
			$file_name_url = urlencode("{$dir}/static/{$filename}");
			$mtime = @filemtime("{$this->path}/static/{$filename}");
			return Minz_Url::display("/ext.php?f={$file_name_url}&amp;{$mtime}", 'php');
		} else {
			$username = Minz_User::name();
			if ($username == null) {
				return '';
			}
			return Minz_Url::display(['c' => 'extension', 'a' => 'serve', 'params' => [
				'x' => $this->getName(),
				'f' => $filename,
				'm' => $this->mtimeFile($filename),	// cache-busting
			]]);
		}
	}

	/**
	 * Register a controller in the Dispatcher.
	 *
	 * @param string $base_name the base name of the controller. Final name will be FreshExtension_<base_name>_Controller.
	 */
	final protected function registerController(string $base_name): void {
		Minz_Dispatcher::registerController($base_name, $this->path);
	}

	/**
	 * Register the views in order to be accessible by the application.
	 */
	final protected function registerViews(): void {
		Minz_View::addBasePathname($this->path);
	}

	/**
	 * Register i18n files from ext_dir/i18n/
	 */
	final protected function registerTranslates(): void {
		$i18n_dir = $this->path . '/i18n';
		Minz_Translate::registerPath($i18n_dir);
	}

	/**
	 * Register a new hook.
	 *
	 * @param Minz_HookType|string $hook_name the hook name (must exist).
	 * @param callable $hook_function the function name to call (must be callable).
	 * @param int $priority the priority of the hook, default priority is 0, the higher the value the lower the priority
	 */
	final protected function registerHook(Minz_HookType|string $hook_name, $hook_function, int $priority = Minz_Hook::DEFAULT_PRIORITY): void {
		Minz_ExtensionManager::addHook($hook_name, $hook_function, $priority);
	}

	/** @param 'system'|'user' $type */
	private function isConfigurationEnabled(string $type): bool {
		if (!class_exists('FreshRSS_Context', false)) {
			return false;
		}

		switch ($type) {
			case 'system': return FreshRSS_Context::hasSystemConf();
			case 'user': return FreshRSS_Context::hasUserConf();
			default:
				return false;
		}
	}

	/** Read from the current context instead of retaining another user's or an earlier request's snapshot.
	 * @param 'system'|'user' $type
	 * @return array<string,mixed>
	 */
	private function configuration(string $type): array {
		if (!$this->isConfigurationEnabled($type) || !$this->isExtensionConfigured($type)) {
			return [];
		}
		$configuration = $type === 'system' ? FreshRSS_Context::systemConf() : FreshRSS_Context::userConf();
		return $configuration->extensions[$this->getName()];
	}

	/** @param 'system'|'user' $type */
	private function isExtensionConfigured(string $type): bool {
		switch ($type) {
			case 'user':
				$conf = FreshRSS_Context::userConf();
				break;
			case 'system':
				$conf = FreshRSS_Context::systemConf();
				break;
			default:
				return false;
		}

		if (!$conf->hasParam('extensions')) {
			return false;
		}

		return array_key_exists($this->getName(), $conf->extensions);
	}

	/**
	 * @return array<string,mixed>
	 * @deprecated Use typed versions instead. Will soon be marked as private.
	 * @internal
	 */
	final protected function getSystemConfiguration(): array {
		return $this->configuration('system');
	}

	/**
	 * @return array<string,mixed>
	 * @deprecated Use typed versions instead. Will soon be marked as private.
	 * @internal
	 */
	final protected function getUserConfiguration(): array {
		return $this->configuration('user');
	}

	/**
	 * @deprecated Use typed versions instead. Will soon be marked as private.
	 * @internal
	 */
	final public function getSystemConfigurationValue(string $key, mixed $default = null): mixed {
		$configuration = $this->configuration('system');
		return array_key_exists($key, $configuration) ? $configuration[$key] : $default;
	}

	/**
	 * @param non-empty-string $key
	 * @return array<int|string,mixed>|null
	 */
	final public function getSystemConfigurationArray(string $key): ?array {
		/** @phpstan-ignore method.deprecated */
		$a = $this->getSystemConfigurationValue($key);
		return is_array($a) ? $a : null;
	}

	/** @param non-empty-string $key */
	final public function getSystemConfigurationBool(string $key): ?bool {
		/** @phpstan-ignore method.deprecated */
		$a = $this->getSystemConfigurationValue($key);
		return is_bool($a) ? $a : null;
	}

	/** @param non-empty-string $key */
	final public function getSystemConfigurationInt(string $key): ?int {
		/** @phpstan-ignore method.deprecated */
		$a = $this->getSystemConfigurationValue($key);
		return is_numeric($a) ? (int)$a : null;
	}

	/** @param non-empty-string $key */
	final public function getSystemConfigurationString(string $key): ?string {
		/** @phpstan-ignore method.deprecated */
		$a = $this->getSystemConfigurationValue($key);
		return is_string($a) ? $a : null;
	}

	/**
	 * @deprecated Use typed versions instead. Will soon be marked as private.
	 * @internal
	 */
	final public function getUserConfigurationValue(string $key, mixed $default = null): mixed {
		$configuration = $this->configuration('user');
		return array_key_exists($key, $configuration) ? $configuration[$key] : $default;
	}

	/**
	 * @param non-empty-string $key
	 * @return array<int|string,mixed>|null
	 */
	final public function getUserConfigurationArray(string $key): ?array {
		// @phpstan-ignore-next-line method.deprecated
		$a = $this->getUserConfigurationValue($key);
		return is_array($a) ? $a : null;
	}

	/** @param non-empty-string $key */
	final public function getUserConfigurationBool(string $key): ?bool {
		// @phpstan-ignore-next-line method.deprecated
		$a = $this->getUserConfigurationValue($key);
		return is_bool($a) ? $a : null;
	}

	/** @param non-empty-string $key */
	final public function getUserConfigurationInt(string $key): ?int {
		// @phpstan-ignore-next-line method.deprecated
		$a = $this->getUserConfigurationValue($key);
		return is_numeric($a) ? (int)$a : null;
	}

	/** @param non-empty-string $key */
	final public function getUserConfigurationString(string $key): ?string {
		// @phpstan-ignore-next-line method.deprecated
		$a = $this->getUserConfigurationValue($key);
		return is_string($a) ? $a : null;
	}

	/**
	 * @param 'system'|'user' $type
	 * @param array<string,mixed> $configuration
	 */
	private function setConfiguration(string $type, array $configuration): void {
		switch ($type) {
			case 'system':
				$conf = FreshRSS_Context::systemConf();
				break;
			case 'user':
				$conf = FreshRSS_Context::userConf();
				break;
			default:
				return;
		}

		if ($conf->hasParam('extensions')) {
			$extensions = $conf->extensions;
		} else {
			$extensions = [];
		}
		$previous = $extensions;
		$extensions[$this->getName()] = $configuration;

		$conf->extensions = $extensions;
		try {
			if (!$conf->save()) {
				throw new Minz_ExtensionException('Could not save extension configuration', $this->name);
			}
		} catch (Throwable $error) {
			$conf->extensions = $previous;
			throw $error;
		}
	}

	/**
	 * @param array<string,mixed> $configuration
	 * @deprecated Use {@see setSystemConfigurationValue()} instead. Will soon be marked as private.
	 * @internal
	 */
	final protected function setSystemConfiguration(array $configuration): void {
		$this->setConfiguration('system', $configuration);
	}

	/**
	 * @param non-empty-string $key
	 * @param array<string,mixed>|mixed|null $value
	 */
	final protected function setSystemConfigurationValue(string $key, $value = null): void {
		$configuration = $this->configuration('system');
		if (array_key_exists($key, $configuration) && $value === null) {
			unset($configuration[$key]);
		} elseif ($value !== null) {
			$configuration[$key] = $value;
		}

		$this->setConfiguration('system', $configuration);
	}

	/**
	 * @param array<string,mixed> $configuration
	 * @deprecated Use {@see setUserConfigurationValue()} instead. Will soon be marked as private.
	 * @internal
	 */
	final protected function setUserConfiguration(array $configuration): void {
		$this->setConfiguration('user', $configuration);
	}

	/**
	 * @param non-empty-string $key
	 * @param array<string,mixed>|mixed|null $value
	 */
	final protected function setUserConfigurationValue(string $key, $value = null): void {
		$configuration = $this->configuration('user');
		if (array_key_exists($key, $configuration) && $value === null) {
			unset($configuration[$key]);
		} elseif ($value !== null) {
			$configuration[$key] = $value;
		}

		$this->setConfiguration('user', $configuration);
	}

	/** @phpstan-param 'system'|'user' $type */
	private function removeConfiguration(string $type): void {
		if (!$this->isConfigurationEnabled($type) || !$this->isExtensionConfigured($type)) {
			return;
		}

		switch ($type) {
			case 'system':
				$conf = FreshRSS_Context::systemConf();
				break;
			case 'user':
				$conf = FreshRSS_Context::userConf();
				break;
			default:
				return;
		}

		$extensions = $conf->extensions;
		$previous = $extensions;
		unset($extensions[$this->getName()]);
		if (empty($extensions)) {
			$extensions = [];
		}
		$conf->extensions = $extensions;
		try {
			if (!$conf->save()) {
				throw new Minz_ExtensionException('Could not remove extension configuration', $this->name);
			}
		} catch (Throwable $error) {
			$conf->extensions = $previous;
			throw $error;
		}
	}

	final protected function removeSystemConfiguration(): void {
		$this->removeConfiguration('system');
	}

	final protected function removeUserConfiguration(): void {
		$this->removeConfiguration('user');
	}

	/** @throws Minz_ExtensionException */
	final protected function saveFile(string $filename, string $content): void {
		$path = $this->getExtensionUserPath();
		if (!self::isSafeFilename($filename) || is_link($path . '/' . $filename)) {
			throw new Minz_ExtensionException('Invalid extension filename', $this->name);
		}

		if (!file_exists($path)) {
			mkdir($path, 0777, true);
		}

		$base = realpath($path);
		$parent = realpath(dirname($path . '/' . $filename));
		if ($base === false || $parent === false ||
			($parent !== $base && !str_starts_with($parent, $base . DIRECTORY_SEPARATOR))) {
			throw new Minz_ExtensionException('Invalid extension file directory', $this->name);
		}
		if (file_put_contents("{$path}/{$filename}", $content, LOCK_EX) === false) {
			throw new Minz_ExtensionException('Could not save extension file', $this->name);
		}
	}

	final protected function removeFile(string $filename): void {
		$path = $this->resolveUserFile($filename);
		if ($path !== null) {
			unlink($this->getExtensionUserPath() . '/' . $filename);
		}
	}

	/**
	 * @param array<string,string> $policies
	 */
	public function amendCsp(array &$policies): void {
		foreach ($this->csp_policies as $policy => $source) {
			if (isset($policies[$policy])) {
				$policies[$policy] .= ' ' . $source;
			} else {
				$policies[$policy] = $source;
			}
		}
	}
}
