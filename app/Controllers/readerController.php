<?php
declare(strict_types=1);

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Exceptions\Rules\RuleException;
use FreshRSS\Infrastructure\Concurrency\UserWorkLock;
use FreshRSS\Infrastructure\Refresh\Queue;
use FreshRSS\Models\Subscriptions\ConfigurationDAO;
use FreshRSS\Services\Extensions\ApiService;
use FreshRSS\Services\Extraction\ExtractionEngine;
use FreshRSS\Services\Reader\ApiContract;
use FreshRSS\Services\Reader\ApiResponse;
use FreshRSS\Services\Reader\BackupPolicyService;
use FreshRSS\Services\Reader\EntryPresenter;
use FreshRSS\Services\Reader\EntryStateService;
use FreshRSS\Services\Reader\ModuleAssets;
use FreshRSS\Services\Reader\Preferences;
use FreshRSS\Services\Reader\SchedulerSettingsService;
use FreshRSS\Services\Reader\SettingsService;
use FreshRSS\Services\Reader\StorageService;
use FreshRSS\Services\Rules\RuleEngine;
use FreshRSS\Services\Subscriptions\ConfigurationService as SubscriptionConfigurationService;
use FreshRSS\Services\Subscriptions\NameValidator;
use FreshRSS\Utils\Http\ApiInput;

/** Home reading interface and same-origin JSON API; no API password is exposed to JavaScript. */
final class FreshRSS_reader_Controller extends FreshRSS_ActionController {
	private bool $readOnly = false;

	#[\Override]
	public function firstAction(): void {
		$this->readOnly = !FreshRSS_Auth::hasAccess();
		if ($this->readOnly) {
			$publicRead = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
				&& in_array(Minz_Request::actionName(), ApiContract::PUBLIC_READ_ACTIONS, true)
				&& FreshRSS_Auth::allowAnonymous() && FreshRSS_Context::hasUserConf() && FreshRSS_Context::userConf()->enabled;
			if (!$publicRead) {
				if (Minz_Request::actionName() === 'index') {
					if (Minz_Request::paramString('user') !== '' || FreshRSS_Auth::allowAnonymous()) {
						Minz_Error::error(403, redirect: false);
					} else {
						Minz_Request::forward(['c' => 'auth', 'a' => 'login']);
					}
					return;
				}
				self::respond(['error' => 'authentication_required'], 403);
			}
			if (Minz_Request::actionName() === 'index' && Minz_Request::paramString('view') === 'settings') {
				Minz_Request::forward(['c' => 'auth', 'a' => 'login']);
				return;
			}
		}
		$this->view->_layout(null);
		header('Cache-Control: private, no-store');
		header('X-Content-Type-Options: nosniff');
		$methods = ApiContract::routes()[Minz_Request::actionName()] ?? ['GET'];
		if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
			header('Allow: ' . implode(', ', $methods));
			self::respond(['error' => 'method_not_allowed'], 405);
		}
		if (Minz_Request::isPost()) {
			$input = fopen('php://input', 'rb');
			if ($input === false) {
				self::respond(['error' => 'request_read_failed'], 400);
			}
			try {
				ApiInput::checkRequestHeaders($_SERVER, ApiInput::limits()['maxRequestBytes']);
				ApiInput::readBody($input, ApiInput::limits()['maxRequestBytes']);
			} catch (UnexpectedValueException $exception) {
				self::respond(['error' => $exception->getMessage()], 415);
			} catch (LengthException $exception) {
				self::respond(['error' => $exception->getMessage()], 413);
			} catch (RuntimeException $exception) {
				self::respond(['error' => 'request_read_failed'], 400);
			} finally {
				fclose($input);
			}
		}
	}

	/** @param array<string,mixed> $data */
	private static function respond(array $data, int $status = 200): never {
		ApiResponse::respond($data, $status);
	}

	public function indexAction(): void {
		header('Content-Type: text/html; charset=UTF-8');
		$this->view->readerScriptNonce = bin2hex(random_bytes(16));
		$this->view->readerModuleAssets = ModuleAssets::importMap();
		$this->_csp([
			'default-src' => "'self'", 'script-src' => "'self' 'nonce-" . $this->view->readerScriptNonce . "'",
			'style-src' => "'self' 'unsafe-inline'",
			'img-src' => "'self' https: http: data:", 'media-src' => "'none'", 'connect-src' => "'self'",
			'frame-src' => "'self'", 'frame-ancestors' => "'none'", 'object-src' => "'none'", 'base-uri' => "'none'",
		]);
		try {
			FreshRSS_Context::updateUsingRequest(true);
		} catch (FreshRSS_Context_Exception) {
			Minz_Error::error(404);
			return;
		}
		$initialQuery = [
			'get' => Minz_Request::paramString('get', plaintext: true) ?: 'a',
			'filter' => Minz_Request::paramString('filter', plaintext: true),
			'search' => Minz_Request::paramString('search', plaintext: true),
		];
		if ($initialQuery['filter'] === '') {
			$filter = array_search(FreshRSS_Context::$state, ApiContract::FILTERS, true);
			$initialQuery['filter'] = $filter === false ? 'all' : $filter;
			if ($filter === false) {
				$initialQuery['state'] = FreshRSS_Context::$state;
			}
		}
		$schema = Preferences::schema(FreshRSS_Context::systemConf()->reader);
		$this->view->readerBootstrap = [
			'initialQuery' => $initialQuery,
			'readOnly' => $this->readOnly,
			'csrf' => $this->readOnly ? '' : FreshRSS_Auth::csrfToken(),
			'apiLimits' => ApiInput::limits(),
			'subscription' => SubscriptionConfigurationService::schema(ApiInput::limits()['maxBatchItems']),
			'language' => FreshRSS_Context::userConf()->language,
			'username' => Minz_User::name(),
			'canManageLimits' => FreshRSS_Auth::hasAccess('admin'),
			'autoMarkArticle' => !$this->readOnly && (bool)(FreshRSS_Context::userConf()->mark_when['article'] ?? false),
			'autoMarkSite' => !$this->readOnly && (bool)(FreshRSS_Context::userConf()->mark_when['site'] ?? false),
			'adjustments' => Preferences::preferenceErrors(FreshRSS_Context::userConf()->reader_preferences, $schema),
			'preferences' => Preferences::preferences(FreshRSS_Context::userConf()->reader_preferences, $schema),
			'schema' => $schema,
			'limits' => FreshRSS_Context::systemConf()->reader,
		];
	}

	public function schedulerAction(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			self::respond(['error' => 'administrator_required'], 403);
		}
		try {
			$historyBefore = ApiContract::historyCursor(Minz_Request::params()['history_before'] ?? null);
			if (Minz_Request::isPost()) {
				$params = Minz_Request::params();
				$keys = array_diff(array_keys($params), ['_csrf', 'c', 'a', 'rid', 'readerSettings', 'history_before']);
				if ($keys === [] || count($keys) !== 1) {
					self::respond(['error' => 'invalid_scheduler_update'], 400);
				}
				if (array_key_exists('configuration', $params) && is_array($params['configuration']) && is_array_keys_string($params['configuration'])) {
					SchedulerSettingsService::save(FreshRSS_Context::systemConf(), $params['configuration']);
				} elseif (array_key_exists('paused', $params)) {
					SchedulerSettingsService::setPaused(Queue::open(), $params['paused']);
				} else {
					self::respond(['error' => 'invalid_scheduler_update'], 400);
				}
			}
			$result = SchedulerSettingsService::describe(FreshRSS_Context::systemConf(), Queue::open(), $historyBefore);
		} catch (ApiException $exception) {
			self::apiFailure($exception);
		} catch (Throwable $exception) {
			Minz_Log::error('Scheduler status unavailable: ' . get_class($exception));
			self::respond(['error' => 'scheduler_unavailable'], 503);
		}
		self::respond($result);
	}

	public function accountSettingsAction(): void {
		$conf = FreshRSS_Context::userConf();
		try {
			if (Minz_Request::isPost()) {
				$values = Minz_Request::params()['values'] ?? null;
				if (!is_array($values) || !is_array_keys_string($values)) {
					self::respond(['error' => 'invalid_settings'], 400);
				}
				$result = SettingsService::save($conf, $values);
				invalidateHttpCache();
			} else {
				$result = SettingsService::describe($conf);
			}
		} catch (ApiException $exception) {
			self::apiFailure($exception);
		}
		self::respond($result);
	}

	public function storageAction(): void {
		try {
			self::respond(StorageService::describe());
		} catch (ApiException $exception) {
			self::apiFailure($exception);
		} catch (Throwable $exception) {
			Minz_Log::error('Reader storage statistics unavailable: ' . get_class($exception));
			self::respond(['error' => 'storage_unavailable'], 503);
		}
	}

	public function backupPolicyAction(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			self::respond(['error' => 'administrator_required'], 403);
		}
		try {
			$conf = FreshRSS_Context::systemConf();
			if (Minz_Request::isPost()) {
				$values = Minz_Request::params()['configuration'] ?? null;
				if (!is_array($values) || !is_array_keys_string($values)) {
					self::respond(['error' => 'invalid_backup_configuration'], 400);
				}
				$result = BackupPolicyService::save($conf, $values);
			} else {
				$result = BackupPolicyService::describe($conf);
			}
		} catch (ApiException $exception) {
			self::apiFailure($exception);
		}
		self::respond($result);
	}

	public function capabilitiesAction(): void {
		if ($this->readOnly) {
			self::respond(['api' => 'freshrss-reader', 'authentication' => 'anonymous', 'readOnly' => true,
				'limits' => ApiInput::limits(), 'pagination' => ['kind' => 'id_cursor', 'order' => 'descending', 'snapshot' => false],
				'capabilities' => array_values(array_diff(ApiContract::PUBLIC_READ_ACTIONS, ['index'])), 'features' => ['search' => true]]);
		}
		self::respond(['api' => 'freshrss-reader', 'authentication' => 'session', 'csrf' => 'required_for_post',
			'limits' => ApiInput::limits(), 'pagination' => ['kind' => 'id_cursor', 'order' => 'descending', 'snapshot' => false],
			'capabilities' => array_values(array_diff(array_keys(ApiContract::routes()), ['index'])),
			'features' => ['search' => true],
			'batch' => ['atomic' => false, 'prevalidateAllIds' => true, 'idempotent' => true],
			'clientApis' => ['greader' => 'api/greader.php', 'fever' => 'api/fever.php']]);
	}

	public function schemaAction(): void {
		if ($this->readOnly) {
			self::respond(['readOnly' => true, 'limits' => ApiInput::limits(),
				'entryId' => ApiContract::entryIdSchema() + ['maximum' => EntryPresenter::ENTRY_ID_MAX],
				'contract' => ApiContract::contract(FreshRSS_Context::systemConf()->reader, ApiInput::limits(), readOnly: true)]);
		}
		self::respond(['preferences' => Preferences::schema(FreshRSS_Context::systemConf()->reader),
			'limits' => ApiInput::limits(), 'entryId' => ApiContract::entryIdSchema()
				+ ['maximum' => EntryPresenter::ENTRY_ID_MAX],
			'subscription' => SubscriptionConfigurationService::schema(ApiInput::limits()['maxBatchItems']),
			'contract' => ApiContract::contract(FreshRSS_Context::systemConf()->reader, ApiInput::limits()),
			'batch' => ['ids' => 'nonempty unique entry ID strings', 'state' => 'exactly one boolean: read or starred'],
			'rulePresets' => RuleEngine::presets(), 'ruleLimits' => RuleEngine::DEFAULT_LIMITS,
			'extractionPresets' => ExtractionEngine::presets()]);
	}

	public function extensionConfigAction(): void {
		$params = Minz_Request::params();
		$service = new ApiService(Minz_ExtensionManager::listExtensions());
		try {
			if (Minz_Request::isPost()) {
				$items = [['extension' => $params['extension'] ?? null, 'configuration' => $params['configuration'] ?? null,
					'expectedVersion' => $params['expectedVersion'] ?? null]];
				$result = $service->save($items, ApiInput::limits()['maxBatchItems']);
				self::respond(['configuration' => array_values($result['extensions'])[0], 'atomic' => true]);
			} elseif (($params['extension'] ?? '') === '') {
				self::respond(['configurations' => $service->catalog()]);
			} else {
				self::respond(['configuration' => $service->describe($params['extension'])]);
			}
		} catch (ApiException $exception) {
			$details = $exception->details;
			// Preserve the single-extension field error shape used by the configuration form.
			$entrypoint = $params['extension'] ?? null;
			if (is_string($entrypoint) && is_array($details['fields'] ?? null) && isset($details['fields'][$entrypoint])) {
				$details['fields'] = $details['fields'][$entrypoint];
			}
			self::apiFailure($exception, $details);
		}
	}

	public function extensionBatchAction(): void {
		try {
			$result = (new ApiService(Minz_ExtensionManager::listExtensions()))->save(
				Minz_Request::params()['items'] ?? null, ApiInput::limits()['maxBatchItems']);
		} catch (ApiException $exception) {
			self::apiFailure($exception);
		}
		self::respond($result);
	}

	/** @param array<string,mixed>|null $details */
	private static function apiFailure(ApiException $exception, ?array $details = null): never {
		if ($exception->getMessage() === 'processing_busy') {
			header('Retry-After: ' . SubscriptionConfigurationService::BUSY_RETRY_AFTER);
		}
		self::respond(['error' => $exception->getMessage()] + ($details ?? $exception->details), $exception->status);
	}

	private static function configurationLock(): UserWorkLock {
		$lock = UserWorkLock::tryAcquire();
		if ($lock === null) {
			header('Retry-After: ' . SubscriptionConfigurationService::BUSY_RETRY_AFTER);
			self::respond(['error' => 'processing_busy', 'retryable' => true], 503);
		}
		return $lock;
	}

	private static function requirePost(): void {
		if (!Minz_Request::isPost()) {
			header('Allow: POST');
			self::respond(['error' => 'method_not_allowed'], 405);
		}
	}

	public function batchAction(): void {
		self::requirePost();
		try {
			$result = (new EntryStateService(FreshRSS_Factory::createEntryDao()))->batch(
				Minz_Request::params(), ApiInput::limits()['maxBatchItems']);
		} catch (ApiException $exception) {
			self::respond(['error' => $exception->getMessage()] + $exception->details, $exception->status);
		}
		self::respond($result);
	}

	/** A category can be created or renamed; names are stored HTML-encoded like the classic UI. */
	public function categoriesAction(): void {
		if (!Minz_Request::isPost()) {
			$this->feedsAction();
			return;
		}
		$lock = self::configurationLock();
		$params = Minz_Request::params();
		try {
			$name = NameValidator::validate($params['name'] ?? null);
		} catch (ApiException $exception) {
			self::respond(['error' => $exception->getMessage()] + $exception->details, $exception->status);
		}
		$id = $params['id'] ?? null;
		if ($id !== null && (!is_int($id) || $id < 1)) {
			self::respond(['error' => 'invalid_id'], 400);
		}
		$dao = FreshRSS_Factory::createCategoryDao();
		$category = $id === null ? null : $dao->searchById($id);
		if ($id !== null && $category === null) {
			self::respond(['error' => 'not_found'], 404);
		}
		if ($category !== null && $category->isDefault()) {
			self::respond(['error' => 'default_category_read_only'], 409);
		}
		$duplicate = $dao->searchByName($name);
		if (($duplicate !== null && $duplicate->id() !== $id) || FreshRSS_Factory::createTagDao()->searchByName($name) !== null) {
			self::respond(['error' => 'name_conflict'], 409);
		}
		if ($category === null) {
			$maximum = FreshRSS_Context::systemConf()->limits['max_categories'];
			if ($maximum > 0 && $dao->count() >= $maximum) {
				self::respond(['error' => 'category_limit', 'max' => $maximum], 409);
			}
			$id = $dao->addCategory(['name' => $name]);
			if ($id === false) {
				self::respond(['error' => 'write_failed'], 500);
			}
		} elseif ($dao->updateCategory($category->id(), ['name' => $name, 'kind' => $category->kind(),
			'attributes' => $category->attributes()]) === false) {
			self::respond(['error' => 'write_failed'], 500);
		}
		invalidateHttpCache();
		$lock->release();
		self::respond(['category' => ['id' => $id, 'name' => html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')]]);
	}

	/** Explicit allow-list; credentials, URLs and user IDs cannot be overwritten through this endpoint. */
	public function feedUpdateAction(): void {
		self::requirePost();
		$lock = self::configurationLock();
		$params = Minz_Request::params();
		$id = $params['id'] ?? null;
		if (!is_int($id) || $id < 1) {
			self::respond(['error' => 'invalid_id'], 400);
		}
		$dao = FreshRSS_Factory::createFeedDao();
		if ($dao->searchById($id) === null) {
			self::respond(['error' => 'not_found'], 404);
		}
		$values = [];
		if (array_key_exists('name', $params)) {
			try {
				$values['name'] = NameValidator::validate($params['name']);
			} catch (ApiException $exception) {
				self::respond(['error' => $exception->getMessage()] + $exception->details, $exception->status);
			}
		}
		if (array_key_exists('categoryId', $params)) {
			$categoryId = $params['categoryId'];
			if (!is_int($categoryId) || $categoryId < 1) {
				self::respond(['error' => 'invalid_category'], 400);
			}
			if (FreshRSS_Factory::createCategoryDao()->searchById($categoryId) === null) {
				self::respond(['error' => 'not_found'], 404);
			}
			$values['category'] = $categoryId;
		}
		if ($values === []) {
			self::respond(['error' => 'empty_update'], 400);
		}
		if (!$dao->updateFeed($id, $values)) {
			self::respond(['error' => 'write_failed'], 500);
		}
		invalidateHttpCache();
		$lock->release();
		self::respond(['id' => $id, 'updated' => array_keys($values)]);
	}

	public function exportAction(): void {
		$this->view->categories = FreshRSS_Factory::createCategoryDao()->listSortedCategories(prePopulateFeeds: true, details: true);
		$this->view->excludeMutedFeeds = false;
		$this->view->includeSensitiveCurlParams = false;
		header('Content-Type: text/x-opml; charset=UTF-8');
		header('Content-Disposition: attachment; filename="subscriptions.opml"');
		echo $this->view->helperToString('export/opml');
		exit;
	}

	public function rulePreviewAction(): void {
		self::requirePost();
		$params = Minz_Request::params();
		$rules = $params['rules'] ?? null;
		$entry = $params['entry'] ?? [];
		$explain = $params['explain'] ?? false;
		$updated = $params['updated'] ?? false;
		if (!is_string($rules) || !is_array($entry) || !is_bool($explain) || !is_bool($updated)) {
			self::respond(['error' => 'invalid_preview'], 400);
		}
		foreach ($entry as $key => $value) {
			if (!is_string($key) || (!is_string($value) && (!is_array($value) || !array_is_list($value) ||
				count(array_filter($value, 'is_string')) !== count($value)))) {
				self::respond(['error' => 'invalid_entry'], 400);
			}
		}
		$operations = $params['operations'] ?? null;
		if ($operations !== null && (!is_array($operations) || !array_is_list($operations))) {
			self::respond(['error' => 'invalid_operations'], 400);
		}
		try {
			if (is_array($operations)) {
				$rules = RuleEngine::patch($rules, $operations, ApiInput::limits()['maxBatchItems']);
			}
			$errors = RuleEngine::validate($rules);
			if ($errors !== []) {
				self::respond(['error' => 'invalid_rules', 'fields' => $errors], 400);
			}
			$preview = RuleEngine::fromJson($rules)->preview($entry, $explain, $updated);
		} catch (RuleException $exception) {
			self::respond(['error' => 'invalid_preview', 'fields' => [$exception->getMessage()]], 400);
		}
		self::respond(['preview' => $preview] + ($operations !== null ? ['patchedRules' => $rules] : []));
	}

	public function extractionPreviewAction(): void {
		self::requirePost();
		$params = Minz_Request::params();
		$source = $params['source'] ?? null;
		$recipe = $params['recipe'] ?? null;
		$baseUrl = $params['baseUrl'] ?? '';
		if (!is_string($source) || !is_array($recipe) || !is_string($baseUrl)) {
			self::respond(['error' => 'invalid_preview'], 400);
		}
		self::respond(['preview' => ExtractionEngine::extract($source, $recipe, $baseUrl)]);
	}

	public function feedsAction(): void {
		try {
			$catalog = (new ConfigurationDAO())->catalog(FreshRSS_Context::userConf()->ttl_default);
		} catch (ApiException $exception) {
			self::respond(['error' => $exception->getMessage()] + $exception->details, $exception->status);
		}
		foreach ($catalog['categories'] as &$category) {
			if ($category['id'] === FreshRSS_CategoryDAO::DEFAULTCATEGORYID) {
				$category['name'] = html_entity_decode(_t('gen.short.default_category'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}
		self::respond($catalog);
	}

	public function feedBatchAction(): void {
		$this->updateSubscriptions(true);
	}

	public function subscriptionBatchAction(): void {
		$this->updateSubscriptions(false);
	}

	private function updateSubscriptions(bool $feedsOnly): void {
		$service = new SubscriptionConfigurationService(new ConfigurationDAO(), FreshRSS_Context::userConf()->ttl_default);
		try {
			$result = $feedsOnly ? $service->feeds(Minz_Request::params(), ApiInput::limits()['maxBatchItems']) :
				$service->subscriptions(Minz_Request::params(), ApiInput::limits()['maxBatchItems']);
		} catch (ApiException $exception) {
			if ($exception->getMessage() === 'processing_busy') {
				header('Retry-After: ' . SubscriptionConfigurationService::BUSY_RETRY_AFTER);
			}
			self::respond(['error' => $exception->getMessage()] + $exception->details, $exception->status);
		}
		self::respond($result);
	}

	public function itemsAction(): void {
		try {
			$request = ApiContract::listing(Minz_Request::params(), FreshRSS_Context::systemConf()->reader);
			FreshRSS_Context::$search = new FreshRSS_BooleanSearch($request['search']);
			if ($request['exhausted']) {
				self::respond(['items' => [], 'next' => null]);
			}
			$items = [];
			foreach (FreshRSS_Factory::createEntryDao()->listSummaries($request['type'], $request['id'], $request['state'],
				FreshRSS_Context::$search, $request['idMax'], $request['limit'] + 1) as $entry) {
				$items[] = EntryPresenter::summary($entry);
			}
		} catch (ApiException $exception) {
			self::apiFailure($exception);
		} catch (Minz_BadRequestException $exception) {
			self::respond(['error' => 'invalid_search', 'fields' => [$exception->getMessage()]], 400);
		} catch (FreshRSS_EntriesGetter_Exception $exception) {
			self::respond(['error' => 'read_failed'], 500);
		}
		$hasMore = count($items) > $request['limit'];
		if ($hasMore) {
			array_pop($items);
		}
		self::respond(['items' => $items, 'next' => $hasMore ? $items[count($items) - 1]['id'] : null]);
	}

	public function articleAction(): void {
		$id = EntryPresenter::entryId(Minz_Request::paramString('id'));
		if ($id === null) {
			self::respond(['error' => 'invalid_id'], 400);
		}
		$entry = FreshRSS_Factory::createEntryDao()->searchById($id);
		if ($entry === null) {
			self::respond(['error' => 'not_found'], 404);
		}
		$entry = Minz_ExtensionManager::callHook(Minz_HookType::EntryBeforeDisplay, $entry);
		if (!($entry instanceof FreshRSS_Entry)) {
			self::respond(['error' => 'not_found'], 404);
		}
		self::respond(['article' => EntryPresenter::summary($entry) + ['content' => $entry->content()]]);
	}

	public function stateAction(): void {
		self::requirePost();
		try {
			$result = (new EntryStateService(FreshRSS_Factory::createEntryDao()))->state(Minz_Request::params());
		} catch (ApiException $exception) {
			self::respond(['error' => $exception->getMessage()] + $exception->details, $exception->status);
		}
		self::respond($result);
	}

	public function preferencesAction(): void {
		$conf = FreshRSS_Context::userConf();
		$schema = Preferences::schema(FreshRSS_Context::systemConf()->reader);
		if (Minz_Request::isPost()) {
			$values = (Minz_Request::params()['preferences'] ?? null);
			if (!is_array($values)) {
				self::respond(['error' => 'invalid_preferences'], 400);
			}
			$errors = Preferences::preferenceErrors($values, $schema);
			if ($errors !== []) {
				self::respond(['error' => 'invalid_preferences', 'fields' => $errors, 'schema' => $schema], 400);
			}
			$written = $conf->mutate(static function (array $latest) use ($values, $schema): array {
				$stored = $latest['reader_preferences'] ?? [];
				$latest['reader_preferences'] = Preferences::preferences(array_replace(is_array($stored) ? $stored : [], $values), $schema);
				return $latest;
			});
			if (!$written) {
				self::respond(['error' => 'write_failed'], 500);
			}
		}
		self::respond(['preferences' => Preferences::preferences($conf->reader_preferences, $schema), 'schema' => $schema]);
	}
	/** Administrators can edit visible ceilings without touching configuration files. */
	public function limitsAction(): void {
		if (!FreshRSS_Auth::hasAccess('admin')) {
			self::respond(['error' => 'administrator_required'], 403);
		}
		if (!Minz_Request::isPost()) {
			header('Allow: POST');
			self::respond(['error' => 'method_not_allowed'], 405);
		}
		$values = Minz_Request::params()['limits'] ?? null;
		$conf = FreshRSS_Context::systemConf();
		$limits = $conf->reader;
		if (!is_array($values) || $values === []) {
			self::respond(['error' => 'invalid_limits'], 400);
		}
		$errors = [];
		foreach ($values as $key => $value) {
			if (!is_string($key) || !array_key_exists($key, $limits) || !is_int($value) || $value < ($key === 'max_page_size' ? 1 : 0)) {
				$errors[] = (string)$key;
			}
		}
		if ($errors !== []) {
			self::respond(['error' => 'invalid_limits', 'fields' => $errors], 400);
		}
		$written = $conf->mutate(static function (array $latest) use ($values): array {
			$current = $latest['reader'] ?? [];
			$latest['reader'] = array_replace(is_array($current) ? $current : [], $values);
			return $latest;
		});
		if (!$written) {
			self::respond(['error' => 'write_failed'], 500);
		}
		$limits = $conf->reader;
		$schema = Preferences::schema($limits);
		self::respond(['limits' => $limits, 'schema' => $schema,
			'preferences' => Preferences::preferences(FreshRSS_Context::userConf()->reader_preferences, $schema)]);
	}

}
