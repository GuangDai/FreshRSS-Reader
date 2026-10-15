<?php
declare(strict_types=1);

use FreshRSS\Services\Extensions\ConfigurationService as ExtensionsConfigurationService;
use FreshRSS\Utils\Http\SettingsEmbedPolicy;

/**
 * The controller to manage extensions.
 *
 * @phpstan-type ExtensionFullMetadata array{name:string,entrypoint:string,author:string,description:string,version:string,type:'system'|'user',url:string,method:string,directory:string,compatibility:string}
 */
class FreshRSS_extension_Controller extends FreshRSS_ActionController {
	/**
	 * This action is called before every other action in that class. It is
	 * the common boiler plate for every action. It is triggered by the
	 * underlying framework.
	 */
	#[\Override]
	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
		}
	}

	/**
	 * This action lists all the extensions available to the current user.
	 */
	public function indexAction(): void {
		FreshRSS_View::prependTitle(_t('admin.extensions.title') . ' · ');
		$this->view->extension_list = [
			'system' => [],
			'user' => [],
		];

		$this->view->extensions_installed = [];

		$extensions = Minz_ExtensionManager::listExtensions();
		foreach ($extensions as $ext) {
			$this->view->extension_list[$ext->getType()][] = $ext;
			$this->view->extensions_installed[$ext->getEntrypoint()] = $ext->getVersion();
		}

		$this->view->available_extensions = $this->getAvailableExtensionList();
	}

	/** Load community metadata separately so installed extensions remain usable offline. */
	public function catalogAction(): void {
		$this->indexAction();
		$this->view->available_extensions = $this->getAvailableExtensionList(true);
		if (SettingsEmbedPolicy::documentRequest()) {
			$this->view->_path('extension/catalogSettings.phtml');
		} else {
			$this->view->_layout(null);
			$this->view->_path('extension/catalog.phtml');
		}
	}

	/**
	 * Fetch extension list from GitHub
	 * @phpstan-return list<ExtensionFullMetadata>
	 */
	protected function getAvailableExtensionList(bool $refresh = false): array {
		if (FreshRSS_Context::userConf()->retrieve_extension_list !== true) {
			return [];
		}
		$cacheFile = CACHE_PATH . '/extension_list.json';
		$json = @file_get_contents($cacheFile) ?: '';
		// The settings page only reads disk. The separate catalogue request may use the network.
		if ($refresh && (time() - (@filemtime($cacheFile) ?: 0) > 86400)) {
			$attemptFile = CACHE_PATH . '/.extension-list-refresh';
			$lock = @fopen($attemptFile, 'c+');
			if ($lock !== false) {
				if (flock($lock, LOCK_EX | LOCK_NB)) {
					$attempt = (int)stream_get_contents($lock);
					if ($attempt < time() - 300) {
						ftruncate($lock, 0);
						rewind($lock);
						fwrite($lock, (string)time());
						fflush($lock);
						$result = FreshRSS_http_Util::httpGet(
							'https://raw.githubusercontent.com/FreshRSS/Extensions/refs/heads/main/extensions.json',
							null, 'json', ['timeout' => 3], [CURLOPT_MAXREDIRS => 2]);
						$decoded = json_decode($result['body'], true);
						if (!$result['fail'] && is_array($decoded) && is_array($decoded['extensions'] ?? null)) {
							$json = $result['body'];
							$tmp = tempnam(CACHE_PATH, '.extension-list-');
							if ($tmp !== false) {
								if (file_put_contents($tmp, $json) !== false) {
									rename($tmp, $cacheFile);
								} else {
									unlink($tmp);
								}
							}
						}
					}
					flock($lock, LOCK_UN);
				}
				fclose($lock);
			}
		}
		if ($json === '') {
			return [];
		}

		// fetch the list as an array
		/** @var array<string,mixed> $list*/
		$list = json_decode($json, true);
		if (!is_array($list) || empty($list['extensions']) || !is_array($list['extensions'])) {
			Minz_Log::warning('Failed to convert extension file list');
			return [];
		}

		// By now, all the needed data is kept in the main extension file.
		// In the future we could fetch detail information from the extensions metadata.json, but I tend to stick with
		// the current implementation for now, unless it becomes too much effort maintain the extension list manually
		$extensions = [];
		foreach ($list['extensions'] as $extension) {
			if (!is_array($extension)) {
				continue;
			}
			if (isset($extension['version']) && is_numeric($extension['version'])) {
				$extension['version'] = (string)$extension['version'];
			}
			if (!array_key_exists('compatibility', $extension)) {
				$extension['compatibility'] = '✔';
			}
			$keys = ['author', 'description', 'directory', 'entrypoint', 'method', 'name', 'type', 'url', 'version', 'compatibility'];
			$extension = array_intersect_key($extension, array_flip($keys));	// Keep only valid keys
			$extension = array_filter($extension, 'is_string');
			foreach ($keys as $key) {
				if (empty($extension[$key])) {
					continue 2;
				}
			}
			if (!in_array($extension['type'], ['system', 'user'], true) || trim($extension['name']) === '' ||
				!preg_match('#^https?://#i', $extension['url']) ||
				preg_match('/^[A-Za-z0-9_-]+$/D', $extension['directory']) !== 1 ||
				preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $extension['entrypoint']) !== 1) {
				continue;
			}
			/** @var ExtensionFullMetadata $extension */
			$extensions[] = $extension;
		}

		return array_map(static function (array $extension) {
			if ($extension['compatibility'] !== '✔') {
				$extension['compatibility'] = version_compare($extension['compatibility'], FRESHRSS_VERSION, '>=') ? '✔' : '✘';
			}
			return $extension;
		}, $extensions);
	}

	/**
	 * This action handles configuration of a given extension.
	 *
	 * Only administrator can configure a system extension.
	 *
	 * Parameters are:
	 * - e: the extension name (urlencoded)
	 * - additional parameters which should be handle by the extension
	 *   handleConfigureAction() method (POST request).
	 */
	public function configureAction(): void {
		if (Minz_Request::paramBoolean('ajax')) {
			$this->view->_layout(null);
		} elseif (Minz_Request::paramBoolean('slider')) {
			$this->indexAction();
			$this->view->_path('extension/index.phtml');
		}

		$ext_name = urldecode(Minz_Request::paramString('e'));
		$ext = Minz_ExtensionManager::findExtension($ext_name);

		if ($ext === null) {
			Minz_Error::error(404);
			return;
		}
		if ($ext->getType() === 'system' && !FreshRSS_Auth::hasAccess('admin')) {
			Minz_Error::error(403);
			return;
		}

		FreshRSS_View::prependTitle($ext->getName() . ' · ' . _t('admin.extensions.title') . ' · ');
		$this->view->extension = $ext;
		try {
			$this->view->extension->handleConfigureAction();
		} catch (Throwable $e) {
			Minz_Log::error('Error while configuring extension ' . $ext->getName() . ': ' . $e->getMessage());
			Minz_Request::bad(_t('feedback.extensions.enable.ko', $ext_name, _url('index', 'logs')), ['c' => 'extension', 'a' => 'index']);
		}
	}

	/** Generic settings UI for user extensions without a custom configure.phtml. */
	public function settingsAction(): void {
		$name = urldecode(Minz_Request::paramString('e'));
		$extension = Minz_ExtensionManager::findExtension($name);
		if ($extension === null || $extension->getType() !== 'user' || $extension->getConfigurationSchema() === []) {
			Minz_Error::error(404);
			return;
		}
		$operation = Minz_Request::paramString('operation');
		if (!Minz_Request::isPost()) {
			if ($operation === 'export') {
				header('Content-Type: application/json; charset=UTF-8');
				header('Cache-Control: private, no-store');
				header('Content-Disposition: attachment; filename="' . $extension->getEntrypoint() . '.json"');
				echo json_encode(ExtensionsConfigurationService::export($extension), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				exit;
			}
			Minz_Error::error(405);
			return;
		}
		$this->view->extension = $extension;
		$this->view->_path('extension/configure.phtml');
		$version = Minz_Request::paramString('settings_version');
		if ($version === '') {
			$this->view->extensionSettingsErrors = ['_version' => _t('admin.extensions.settings.conflict')];
			return;
		}
		try {
			if ($operation === 'import') {
				$result = ExtensionsConfigurationService::import($extension, Minz_Request::paramString('settings_import', plaintext: true),
					expectedVersion: $version);
			} elseif ($operation === 'save') {
				$fields = Minz_Request::params()['settings'] ?? [];
				if (!is_array($fields)) {
					$this->view->extensionSettingsErrors = ['_form' => 'Invalid settings form'];
					return;
				}
				$form = ExtensionsConfigurationService::formValues($extension->getConfigurationSchema(), $fields);
				$this->view->extensionSettingsSubmitted = $form['configuration'];
				if ($form['errors'] !== []) {
					$this->view->extensionSettingsErrors = $form['errors'];
					return;
				}
				$result = ExtensionsConfigurationService::save($extension, $form['configuration'], $version);
			} else {
				Minz_Error::error(400);
				return;
			}
			if (!$result['saved']) {
				if (isset($result['conflict'])) {
					Minz_Request::bad(_t('admin.extensions.settings.conflict'),
						['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $name]]);
					return;
				}
				$this->view->extensionSettingsErrors = $result['errors'];
				return;
			}
			FreshRSS_UserDAO::touch();
			Minz_Request::good(_t('feedback.conf.updated'), ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $name]]);
		} catch (Throwable $error) {
			Minz_Log::error('Extension settings failed for ' . $name . ': ' . get_class($error));
			$this->view->extensionSettingsErrors = ['_save' => _t('feedback.extensions.enable.ko', $name, _url('index', 'logs'))];
		}
	}

	/**
	 * This action enables a disabled extension for the current user.
	 *
	 * System extensions can only be enabled by an administrator.
	 * This action must be reached by a POST request.
	 *
	 * Parameter is:
	 * - e: the extension name (urlencoded).
	 */
	public function enableAction(): void {
		$url_redirect = ['c' => 'extension', 'a' => 'index'];

		if (Minz_Request::isPost()) {
			$ext_name = urldecode(Minz_Request::paramString('e'));
			$ext = Minz_ExtensionManager::findExtension($ext_name);

			if (is_null($ext)) {
				Minz_Request::bad(_t('feedback.extensions.not_found', $ext_name), $url_redirect);
				return;
			}

			if ($ext->isEnabled()) {
				Minz_Request::bad(_t('feedback.extensions.already_enabled', $ext_name), $url_redirect);
			}

			$type = $ext->getType();
			if ($type !== 'user' && !FreshRSS_Auth::hasAccess('admin')) {
				Minz_Request::bad(_t('feedback.extensions.no_access', $ext_name), $url_redirect);
				return;
			}

			$conf = null;
			if ($type === 'system') {
				$conf = FreshRSS_Context::systemConf();
			} elseif ($type === 'user') {
				$conf = FreshRSS_Context::userConf();
			}

			$res = Minz_ExtensionManager::installAndEnable($ext_name);

			if ($conf !== null && $res === true) {
				$ext_list = $conf->extensions_enabled;
				$ext_list = array_filter($ext_list, static function (string $key) use ($type) {
					// Remove from list the extensions that have disappeared or changed type
					$extension = Minz_ExtensionManager::findExtension($key);
					return $extension !== null && $extension->getType() === $type;
				}, ARRAY_FILTER_USE_KEY);

				$ext_list[$ext_name] = true;
				$previous = $conf->extensions_enabled;
				$conf->extensions_enabled = $ext_list;
				if (!$conf->save()) {
					$conf->extensions_enabled = $previous;
					Minz_Log::warning('Could not save enabled extension preferences: ' . $ext_name);
					Minz_Request::bad(_t('feedback.extensions.enable.ko', $ext_name, _url('index', 'logs')), $url_redirect);
					return;
				}

				Minz_Request::good(
					_t('feedback.extensions.enable.ok', $ext_name),
					$url_redirect,
					showNotification: FreshRSS_Context::userConf()->good_notification_timeout > 0
				);
			} else {
				Minz_Log::warning('Cannot enable extension ' . $ext_name . ': ' . $res);
				Minz_Request::bad(_t('feedback.extensions.enable.ko', $ext_name, _url('index', 'logs')), $url_redirect);
			}
		}

		Minz_Request::forward($url_redirect, true);
	}

	/**
	 * This action disables an enabled extension for the current user.
	 *
	 * System extensions can only be disabled by an administrator.
	 * This action must be reached by a POST request.
	 *
	 * Parameter is:
	 * - e: the extension name (urlencoded).
	 */
	public function disableAction(): void {
		$url_redirect = ['c' => 'extension', 'a' => 'index'];

		if (Minz_Request::isPost()) {
			$ext_name = urldecode(Minz_Request::paramString('e'));
			$ext = Minz_ExtensionManager::findExtension($ext_name);

			if (is_null($ext)) {
				Minz_Request::bad(_t('feedback.extensions.not_found', $ext_name), $url_redirect);
				return;
			}

			if (!$ext->isEnabled()) {
				Minz_Request::bad(_t('feedback.extensions.not_enabled', $ext_name), $url_redirect);
			}

			$type = $ext->getType();
			if ($type !== 'user' && !FreshRSS_Auth::hasAccess('admin')) {
				Minz_Request::bad(_t('feedback.extensions.no_access', $ext_name), $url_redirect);
				return;
			}

			$conf = null;
			if ($type === 'system') {
				$conf = FreshRSS_Context::systemConf();
			} elseif ($type === 'user') {
				$conf = FreshRSS_Context::userConf();
			}

			$dependants = Minz_ExtensionManager::getDependants($ext_name);
			$res = 'Required by: ' . implode(', ', $dependants);
			if ($dependants === []) {
				try {
					$res = $ext->uninstall();
				} catch (Throwable $error) {
					$res = $error->getMessage();
				}
			}

			if ($conf !== null && $res === true) {
				$ext_list = $conf->extensions_enabled;
				$ext_list = array_filter($ext_list, static function (string $key) use ($type) {
					// Remove from list the extensions that have disappeared or changed type
					$extension = Minz_ExtensionManager::findExtension($key);
					return $extension !== null && $extension->getType() === $type;
				}, ARRAY_FILTER_USE_KEY);

				$ext_list[$ext_name] = false;
				$previous = $conf->extensions_enabled;
				$conf->extensions_enabled = $ext_list;
				if (!$conf->save()) {
					$conf->extensions_enabled = $previous;
					Minz_Log::warning('Could not save disabled extension preferences: ' . $ext_name);
					Minz_Request::bad(_t('feedback.extensions.disable.ko', $ext_name, _url('index', 'logs')), $url_redirect);
					return;
				}

				Minz_Request::good(
					_t('feedback.extensions.disable.ok', $ext_name),
					$url_redirect,
					showNotification: FreshRSS_Context::userConf()->good_notification_timeout > 0
				);
			} else {
				Minz_Log::warning('Cannot disable extension ' . $ext_name . ': ' . $res);
				Minz_Request::bad(_t('feedback.extensions.disable.ko', $ext_name, _url('index', 'logs')), $url_redirect);
			}
		}

		Minz_Request::forward($url_redirect, true);
	}

	/**
	 * This action handles deletion of an extension.
	 *
	 * Only administrator can remove an extension.
	 * This action must be reached by a POST request.
	 *
	 * Parameter is:
	 * -e: extension name (urlencoded)
	 */
	public function removeAction(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			Minz_Error::error(403);
		}

		$url_redirect = ['c' => 'extension', 'a' => 'index'];

		if (Minz_Request::isPost()) {
			$ext_name = urldecode(Minz_Request::paramString('e'));
			$ext = Minz_ExtensionManager::findExtension($ext_name);

			if (is_null($ext)) {
				Minz_Request::bad(_t('feedback.extensions.not_found', $ext_name), $url_redirect);
				return;
			}

			// Only immediate, real third-party directories may be removed through the UI.
			$path = realpath($ext->getPath());
			$root = realpath(THIRDPARTY_EXTENSIONS_PATH);
			$res = $path !== false && $root !== false && dirname($path) === $root &&
				!is_link($ext->getPath()) && !$ext->isEnabled() && Minz_ExtensionManager::getDependants($ext_name) === [] &&
				recursive_unlink($path);
			if ($res) {
				Minz_Request::good(
					_t('feedback.extensions.removed', $ext_name),
					$url_redirect,
					showNotification: FreshRSS_Context::userConf()->good_notification_timeout > 0
				);
			} else {
				Minz_Request::bad(_t('feedback.extensions.cannot_remove', $ext_name), $url_redirect);
			}
		}

		Minz_Request::forward($url_redirect, true);
	}

	// Supported types with their associated content type
	public const MIME_TYPES = [
		'css' => 'text/css; charset=UTF-8',
		'gif' => 'image/gif',
		'jpeg' => 'image/jpeg',
		'jpg' => 'image/jpeg',
		'js' => 'application/javascript; charset=UTF-8',
		'png' => 'image/png',
		'svg' => 'image/svg+xml',
	];

	public function serveAction(): void {
		$extensionName = Minz_Request::paramString('x');
		$filename = Minz_Request::paramString('f');
		$mimeType = pathinfo($filename, PATHINFO_EXTENSION);
		if ($extensionName === '' || $filename === '' || $mimeType === '' || empty(self::MIME_TYPES[$mimeType])) {
			header('HTTP/1.1 400 Bad Request');
			die('Bad Request!');
		}
		$extension = Minz_ExtensionManager::findExtension($extensionName);
		if ($extension === null || !$extension->isEnabled() || ($mtime = $extension->mtimeFile($filename)) === null) {
			header('HTTP/1.1 404 Not Found');
			die('Not Found!');
		}

		$this->view->_layout(null);

		$content_type = self::MIME_TYPES[$mimeType];
		header("Content-Type: {$content_type}");
		header("Content-Disposition: inline; filename='{$filename}'");
		header('Referrer-Policy: same-origin');
		if (file_exists(DATA_PATH . '/no-cache.txt') || !httpConditional($mtime, cacheSeconds: 604800, cachePrivacy: 2)) {
			$contents = $extension->getFile($filename);
			$this->view->content = is_string($contents) ? $contents : '';
		}
	}
}
