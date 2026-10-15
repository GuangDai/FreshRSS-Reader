<?php
declare(strict_types=1);

/**
 * Manage configuration for the application.
 * @property string $base_url
 * @property array{type:'mysql'|'pgsql'|'sqlite',host:string,user:string,password:string,base:string,prefix:string,
 *  connection_uri_params:string,pdo_options:array<int,int|string|bool>} $db
 * @property bool $disable_update
 * @property string $environment
 * @property array<string,bool> $extensions_enabled
 * @property ''|'error'|'warning'|'notice'|'info'|'debug' $log_level
 * @property-read string $mailer
 * @property-read array{'hostname':string,'host':string,'auth':bool,'username':string,'password':string,
 *  'secure':string,'auto_tls':bool,'port':int,'from':string} $smtp
 * @property string $title
 */
class Minz_Configuration {
	/**
	 * The list of configurations.
	 * @var array<string,static>
	 */
	private static array $config_list = [];

	/**
	 * Add a new configuration to the list of configuration.
	 *
	 * @param string $namespace the name of the current configuration
	 * @param string $config_filename the filename of the configuration
	 * @param string $default_filename a filename containing default values for the configuration
	 * @param Minz_ConfigurationSetterInterface $configuration_setter an optional helper to set values in configuration @deprecated
	 * @throws Minz_FileNotExistException
	 */
	public static function register(string $namespace, string $config_filename, ?string $default_filename = null,
		?Minz_ConfigurationSetterInterface $configuration_setter = null): void {
		self::$config_list[$namespace] = new static(
			$namespace, $config_filename, $default_filename, $configuration_setter
		);
	}

	/**
	 * Parse a file and return its data.
	 *
	 * @param string $filename the name of the file to parse.
	 * @return array<string,mixed> of values
	 * @throws Minz_FileNotExistException if the file does not exist or is invalid.
	 */
	public static function load(string $filename): array {
		$data = @include $filename;
		if (is_array($data) && is_array_keys_string($data)) {
			return $data;
		} else {
			throw new Minz_FileNotExistException($filename);
		}
	}

	/**
	 * Return the configuration related to a given namespace.
	 *
	 * @param string $namespace the name of the configuration to get.
	 * @throws Minz_ConfigurationNamespaceException if the namespace does not exist.
	 */
	public static function get(string $namespace): static {
		if (!isset(self::$config_list[$namespace])) {
			throw new Minz_ConfigurationNamespaceException(
				$namespace . ' namespace does not exist'
			);
		}

		return self::$config_list[$namespace];
	}

	/**
	 * The namespace of the current configuration.
	 */
	private string $namespace = '';

	/**
	 * The filename for the current configuration.
	 */
	private string $config_filename = '';

	/**
	 * The filename for the current default values, null by default.
	 */
	private ?string $default_filename = null;

	/**
	 * The configuration values, an empty array by default.
	 * @var array<string,mixed>
	 */
	private array $data = [];

	/** @var array<string,mixed> Last successfully loaded or published snapshot. */
	private array $loaded_data = [];

	/** @var array<string,true> Reject recursive writes even through another instance of the same file. */
	private static array $savingFiles = [];

	/**
	 * An object which help to set good values in configuration.
	 */
	private ?Minz_ConfigurationSetterInterface $configuration_setter = null;

	/**
	 * Create a new Minz_Configuration object.
	 *
	 * @param string $namespace the name of the current configuration.
	 * @param string $config_filename the file containing configuration values.
	 * @param string $default_filename the file containing default values, null by default.
	 * @param Minz_ConfigurationSetterInterface $configuration_setter an optional helper to set values in configuration @deprecated
	 * @throws Minz_FileNotExistException
	 */
	final private function __construct(string $namespace, string $config_filename, ?string $default_filename = null,
		?Minz_ConfigurationSetterInterface $configuration_setter = null) {
		$this->namespace = $namespace;
		$this->config_filename = $config_filename;
		$this->default_filename = $default_filename;
		$this->_configurationSetter($configuration_setter);

		if ($this->default_filename != null) {
			$this->data = self::load($this->default_filename);
		}

		try {
			$overloaded = array_replace_recursive(
				$this->data, self::load($this->config_filename)
			);
			$this->data = array_filter($overloaded, 'is_string', ARRAY_FILTER_USE_KEY);
		} catch (Minz_FileNotExistException $e) {
			if ($this->default_filename == null) {
				throw $e;
			}
		}
		$this->loaded_data = $this->data;
	}

	/**
	 * Set a configuration setter for the current configuration.
	 * @param Minz_ConfigurationSetterInterface|null $configuration_setter the setter to call when modifying data.
	 */
	public function _configurationSetter(?Minz_ConfigurationSetterInterface $configuration_setter): void {
		if (is_callable([$configuration_setter, 'handle'])) {
			$this->configuration_setter = $configuration_setter;
		}
	}

	#[Deprecated]
	public function configurationSetter(): ?Minz_ConfigurationSetterInterface {
		return $this->configuration_setter;
	}

	/**
	 * Check if a parameter is defined in the configuration
	 */
	public function hasParam(string $key): bool {
		return isset($this->data[$key]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return $this->data;
	}

	/**
	 * Return the value of the given param.
	 *
	 * @param string $key the name of the param.
	 * @param mixed $default default value to return if key does not exist.
	 * @return array|mixed value corresponding to the key.
	 */
	#[Deprecated('Use `attribute*()` methods instead.')]
	public function param(string $key, mixed $default = null): mixed {
		if (isset($this->data[$key])) {
			return $this->data[$key];
		} elseif ($default !== null) {
			return $default;
		} else {
			Minz_Log::warning($key . ' does not exist in configuration');
			return null;
		}
	}

	/**
	 * @return array|mixed
	 */
	public function __get(string $key): mixed {
		if (isset($this->data[$key])) {
			return $this->data[$key];
		}
		Minz_Log::warning($key . ' does not exist in configuration');
		return null;
	}

	/**
	 * Set or remove a param.
	 *
	 * @param string $key the param name to set.
	 * @param mixed $value the value to set. If null, the key is removed from the configuration.
	 */
	#[Deprecated('Use `_attribute()` instead.')]
	public function _param(string $key, mixed $value = null): void {
		if ($this->configuration_setter !== null && $this->configuration_setter->support($key)) {
			$this->configuration_setter->handle($this->data, $key, $value);
		} elseif (isset($this->data[$key]) && $value === null) {
			unset($this->data[$key]);
		} elseif ($value !== null) {
			$this->data[$key] = $value;
		}
	}

	/**
	 * {@see Minz_Configuration::_attribute()} instead.
	 * @param string $key the param name to set.
	 * @param mixed $value the value to set. If null, the key is removed.
	 */
	public function __set(string $key, mixed $value): void {
		if ($value === null) {
			unset($this->data[$key]);
		} else {
			$this->data[$key] = $value;
		}
	}

	/**
	 * Save the current configuration in the configuration file.
	 */
	public function save(): bool {
		return $this->saveData();
	}

	/**
	 * Opaque version of the loaded snapshot, bound to this configuration file.
	 * A configured system salt is required before exposing versions to API clients.
	 * @throws LogicException
	 */
	public function version(): string {
		return $this->versionFor($this->loaded_data);
	}

	/** @param array<string,mixed> $data */
	private function versionFor(array $data): string {
		$system = self::$config_list['system'] ?? null;
		$secret = $this->namespace === 'system' ? ($data['salt'] ?? null) : ($system?->loaded_data['salt'] ?? null);
		if (!is_string($secret) || $secret === '') {
			throw new LogicException('Configuration versions require a non-empty system salt');
		}
		return hash_hmac('sha256', $this->config_filename . "\0" . serialize($data), $secret);
	}

	/**
	 * Merge top-level values into the latest file; null removes a key. Nested arrays are replaced as a whole.
	 * @param array<string,mixed> $changes
	 * @throws Minz_ConfigurationConflictException
	 * @throws Throwable
	 */
	public function savePatch(array $changes, ?string $expectedVersion = null): bool {
		return $this->mutate(static function (array $latest) use ($changes): array {
			foreach ($changes as $key => $value) {
				if ($value === null) {
					unset($latest[$key]);
				} else {
					$latest[$key] = $value;
				}
			}
			return $latest;
		}, $expectedVersion);
	}

	/**
	 * Transform the latest configuration while holding its write lock. The callback must not call save methods.
	 * Failures leave both the current file and this object's data unchanged; exceptions propagate to the caller.
	 * @param callable(array<string,mixed>):array<string,mixed> $transform
	 * @throws Minz_ConfigurationConflictException
	 * @throws Throwable
	 */
	public function mutate(callable $transform, ?string $expectedVersion = null): bool {
		return $this->saveData($transform, $expectedVersion);
	}

	/**
	 * @param callable(array<string,mixed>):array<string,mixed>|null $transform
	 * @throws Throwable
	 */
	private function saveData(?callable $transform = null, ?string $expectedVersion = null): bool {
		$identity = realpath($this->config_filename) ?: $this->config_filename;
		if (isset(self::$savingFiles[$identity])) {
			throw new LogicException('Recursive configuration save is not allowed');
		}
		self::$savingFiles[$identity] = true;
		$originalData = $this->data;
		$originalLoadedData = $this->loaded_data;
		$published = false;
		$lock = null;
		$tmp_filename = '';
		try {
			// Keep the lock inode stable while replacing the configuration itself atomically.
			$lock = @fopen($this->config_filename . '.lock', 'c');
			if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
				return false;
			}
			try {
				$defaults = $this->default_filename === null ? [] : self::load($this->default_filename);
			} catch (Minz_FileNotExistException $e) {
				return false;
			}
			$data = $this->data;
			if ($transform !== null) {
				if (function_exists('opcache_invalidate')) {
					opcache_invalidate($this->config_filename, true);
				}
				try {
					$latest = array_filter(array_replace_recursive($defaults, self::load($this->config_filename)), 'is_string', ARRAY_FILTER_USE_KEY);
				} catch (Minz_FileNotExistException $e) {
					return false;
				}
				if ($expectedVersion !== null && !hash_equals($this->versionFor($latest), $expectedVersion)) {
					throw new Minz_ConfigurationConflictException('The configuration changed since it was loaded');
				}
				$data = $transform($latest);
				if (!is_array_keys_string($data)) {
					throw new UnexpectedValueException('Configuration mutations must return an array with string keys');
				}
			}
			$effectiveData = array_filter(array_replace_recursive($defaults, $data), 'is_string', ARRAY_FILTER_USE_KEY);
			clearstatcache(true, $this->config_filename);
			$permissions = @fileperms($this->config_filename);
			if ($permissions === false) {
				return false;
			}
			$tmp_filename = $this->config_filename . '.' . bin2hex(random_bytes(8)) . '.tmp.php';
			$tmp = @fopen($tmp_filename, 'x');
			if (!is_resource($tmp)) {
				return false;
			}
			fclose($tmp);
			if (!@chmod($tmp_filename, $permissions & 0777)) {
				return false;
			}
			$content = "<?php\nreturn " . var_export($data, true) . ';';
			if (@file_put_contents($tmp_filename, $content) !== strlen($content)) {
				return false;
			}
			$back_filename = $this->config_filename . '.bak.php';
			$backup = @fopen($back_filename, 'c');
			if (!is_resource($backup)) {
				return false;
			}
			fclose($backup);
			if (!@chmod($back_filename, $permissions & 0777) || !@copy($this->config_filename, $back_filename)) {
				return false;
			}
			if (!@rename($tmp_filename, $this->config_filename)) {
				return false;
			}
			// Clear PHP cache only after the replacement succeeded, including same-second writes.
			if (function_exists('opcache_invalidate')) {
				opcache_invalidate($this->config_filename, true);
			}
			$this->data = $effectiveData;
			$this->loaded_data = $effectiveData;
			$published = true;
			return true;
		} finally {
			if ($tmp_filename !== '') {
				@unlink($tmp_filename);
			}
			if (is_resource($lock)) {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
			if (!$published) {
				$this->data = $originalData;
				$this->loaded_data = $originalLoadedData;
			}
			unset(self::$savingFiles[$identity]);
		}
	}

	/**
	 * @param non-empty-string $key
	 * @return array<int|string,mixed>|null
	 */
	public function attributeArray(string $key): ?array {
		$a = $this->data[$key] ?? null;
		return is_array($a) ? $a : null;
	}

	/** @param non-empty-string $key */
	public function attributeBool(string $key): ?bool {
		$a = $this->data[$key] ?? null;
		return is_bool($a) ? $a : null;
	}

	/** @param non-empty-string $key */
	public function attributeInt(string $key): ?int {
		$a = $this->data[$key] ?? null;
		return is_numeric($a) ? (int)$a : null;
	}

	/** @param non-empty-string $key */
	public function attributeString(string $key): ?string {
		$a = $this->data[$key] ?? null;
		return is_string($a) ? $a : null;
	}

	/**
	 * @param non-empty-string $key
	 * @param array<string,mixed>|mixed|null $value Value, not HTML-encoded
	 */
	public function _attribute(string $key, $value = null): void {
		if (isset($this->data[$key]) && $value === null) {
			unset($this->data[$key]);
		} elseif ($value !== null) {
			$this->data[$key] = $value;
		}
	}
}
