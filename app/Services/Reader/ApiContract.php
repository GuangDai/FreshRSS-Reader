<?php
declare(strict_types=1);

namespace FreshRSS\Services\Reader;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Services\Refresh\SchedulerConfiguration;
use FreshRSS\Services\Subscriptions\ConfigurationService as SubscriptionConfigurationService;
use FreshRSS_DatabaseDAO;
use FreshRSS_Entry;

/** Machine-readable reader routes and schemas. Limits come from the same configuration used by request validation. */
final class ApiContract {
	public const VERSION = 1;
	public const STREAM_PATTERN = '^([fc])_([1-9][0-9]{0,9})$';
	public const STREAMS = ['a', 'A', 's', 'i'];
	public const FILTERS = ['all' => FreshRSS_Entry::STATE_ALL, 'unread' => FreshRSS_Entry::STATE_NOT_READ, 'starred' => FreshRSS_Entry::STATE_FAVORITE];

	/** This table also enforces methods in the controller.
	 * @return array<string,list<'GET'|'POST'>>
	 */
	public static function routes(): array {
		return [
			'index' => ['GET'], 'capabilities' => ['GET'], 'schema' => ['GET'], 'scheduler' => ['GET', 'POST'],
			'accountSettings' => ['GET', 'POST'], 'storage' => ['GET'], 'backupPolicy' => ['GET', 'POST'],
			'feeds' => ['GET'], 'items' => ['GET'], 'article' => ['GET'], 'state' => ['POST'], 'batch' => ['POST'],
			'categories' => ['GET', 'POST'], 'feedUpdate' => ['POST'], 'feedBatch' => ['POST'], 'subscriptionBatch' => ['POST'], 'export' => ['GET'],
			'rulePreview' => ['POST'], 'extractionPreview' => ['POST'], 'preferences' => ['GET', 'POST'],
			'limits' => ['POST'], 'extensionConfig' => ['GET', 'POST'], 'extensionBatch' => ['POST'],
		];
	}

	/** @return array<string,mixed> */
	public static function entryIdSchema(): array {
		return ['type' => 'string', 'pattern' => EntryPresenter::ENTRY_ID_PATTERN,
			'format' => 'freshrss-entry-id', 'x-maximumDecimal' => EntryPresenter::ENTRY_ID_MAX];
	}

	/** @param array<string,mixed> $params
	 * @param array<string,int> $readerLimits
	 * @return array{type:'a'|'A'|'s'|'i'|'f'|'c',id:int,state:int,idMax:numeric-string,limit:int,search:string,exhausted:bool}
	 * @throws ApiException
	 */
	public static function listing(array $params, array $readerLimits): array {
		$get = $params['get'] ?? 'a';
		$get = $get === '' ? 'a' : $get;
		$id = 0;
		if (!is_string($get)) {
			throw new ApiException('invalid_stream');
		}
		if (preg_match('/' . self::STREAM_PATTERN . '/D', $get, $matches)) {
			$type = $matches[1];
			$id = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($id === false) {
				throw new ApiException('invalid_stream');
			}
		} elseif (in_array($get, self::STREAMS, true)) {
			$type = $get;
		} else {
			throw new ApiException('invalid_stream');
		}
		$filter = $params['filter'] ?? 'all';
		$filter = $filter === '' ? 'all' : $filter;
		if (!is_string($filter) || !isset(self::FILTERS[$filter])) {
			throw new ApiException('invalid_filter');
		}
		$cursor = $params['cursor'] ?? '';
		if (!is_string($cursor) || ($cursor !== '' && EntryPresenter::entryId($cursor) === null)) {
			throw new ApiException('invalid_cursor');
		}
		$schema = Preferences::schema($readerLimits);
		$maximum = min(PHP_INT_MAX - 1, $schema['pageSize']['max'] ?? 1);
		$requested = $params['limit'] ?? $schema['pageSize']['default'];
		$limit = is_string($requested) || is_int($requested) ? filter_var($requested, FILTER_VALIDATE_INT) : false;
		if ($limit === false || $limit < 1 || $limit > $maximum) {
			throw new ApiException('page_size_out_of_range', details: ['min' => 1, 'max' => $maximum]);
		}
		$search = $params['search'] ?? '';
		if (!is_string($search) || !mb_check_encoding($search, 'UTF-8')) {
			throw new ApiException('invalid_search');
		}
		if (mb_strlen($search, 'UTF-8') > $readerLimits['max_search_chars']) {
			throw new ApiException('search_too_long', details: ['max' => $readerLimits['max_search_chars']]);
		}
		/** @var numeric-string $idMax */
		$idMax = $cursor === '' ? '0' : gmp_strval(gmp_sub($cursor, 1));
		return ['type' => $type, 'id' => $id, 'state' => self::FILTERS[$filter], 'idMax' => $idMax,
			'limit' => $limit, 'search' => $search, 'exhausted' => $cursor === '1'];
	}

	/** Accept only a positive decimal query integer, without silently accepting booleans or overflowing casts.
	 * @throws ApiException
	 */
	public static function historyCursor(mixed $value): ?int {
		if ($value === null) {
			return null;
		}
		if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9][0-9]*$/D', (string)$value) !== 1) {
			throw new ApiException('invalid_history_cursor');
		}
		$cursor = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($cursor === false) {
			throw new ApiException('invalid_history_cursor');
		}
		return $cursor;
	}

	/** @param array<string,int> $readerLimits
	 * @param array{maxBatchItems:int,maxRequestBytes:int} $apiLimits
	 * @return array<string,mixed>
	 */
	public static function contract(array $readerLimits, array $apiLimits): array {
		$preferences = Preferences::schema($readerLimits);
		$entryId = self::entryIdSchema();
		$integerId = ['type' => 'integer', 'minimum' => 1];
		$name = ['type' => 'string', 'minLength' => 1, 'x-maxHtmlEncodedBytes' => FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE];
		$boolean = ['type' => 'boolean'];
		$state = ['read' => $boolean, 'starred' => $boolean];
		$oneState = ['oneOf' => [['required' => ['read'], 'not' => ['required' => ['starred']]],
			['required' => ['starred'], 'not' => ['required' => ['read']]]]];
		$preferenceFields = [];
		foreach ($preferences as $key => $spec) {
			$field = ['type' => $spec['type'] === 'enum' ? 'string' : $spec['type'], 'default' => $spec['default']];
			foreach (['options' => 'enum', 'min' => 'minimum', 'max' => 'maximum'] as $from => $to) {
				if (isset($spec[$from])) {
					$field[$to] = $spec[$from];
				}
			}
			$preferenceFields[$key] = $field;
		}
		$limitFields = [];
		foreach ($readerLimits as $key => $value) {
			$limitFields[$key] = ['type' => 'integer', 'minimum' => $key === 'max_page_size' ? 1 : 0, 'default' => $value];
		}
		$subscriptionFields = [];
		foreach (SubscriptionConfigurationService::fields() as $key => $spec) {
			$field = ['type' => $spec['type']];
			foreach (['min' => 'minimum', 'max' => 'maximum', 'options' => 'enum',
				'minLength' => 'minLength', 'maxLength' => 'x-maxHtmlEncodedBytes'] as $from => $to) {
				if (isset($spec[$from])) {
					$field[$to] = $spec[$from];
				}
			}
			$subscriptionFields[$key] = $field;
		}
		$patch = self::object($subscriptionFields) + ['additionalProperties' => false, 'minProperties' => 1];
		$revision = ['type' => 'string', 'pattern' => SubscriptionConfigurationService::VERSION_PATTERN];
		$accountFields = [];
		foreach (SettingsService::fields() as $key => $spec) {
			$field = match ($spec['type']) {
				'integer_or_false' => ['anyOf' => [['type' => 'integer', 'minimum' => $spec['min'] ?? 0], ['const' => false]]],
				'duration' => ['anyOf' => [['type' => 'string', 'pattern' => SettingsService::DURATION_PATTERN], ['const' => false]]],
				'string_list' => ['type' => 'array', 'items' => ['type' => 'string']],
				default => ['type' => $spec['type']],
			};
			foreach (['min' => 'minimum', 'max' => 'maximum', 'options' => 'enum'] as $from => $to) {
				if (isset($spec[$from]) && $spec['type'] !== 'integer_or_false') {
					$field[$to] = $spec[$from];
				}
			}
			$accountFields[$key] = $field;
		}
		$accountPatch = self::object($accountFields) + ['additionalProperties' => false, 'minProperties' => 1];
		$schedulerFields = [];
		foreach (SchedulerConfiguration::schema() as $key => $spec) {
			$field = ['type' => is_bool($spec['default']) ? 'boolean' : 'integer', 'default' => $spec['default']];
			foreach (['min' => 'minimum', 'max' => 'maximum'] as $from => $to) {
				if (isset($spec[$from])) {
					$field[$to] = $spec[$from];
				}
			}
			$schedulerFields[$key] = $field;
		}
		$schedulerPatch = self::object($schedulerFields) + ['additionalProperties' => false, 'minProperties' => 1];
		$backupFields = ['enabled' => ['type' => 'boolean', 'default' => false],
			'retention' => ['type' => 'integer', 'minimum' => 1, 'default' => 7]];
		$backupPatch = self::object($backupFields) + ['additionalProperties' => false, 'minProperties' => 1];
		$historyCursor = ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX];

		$requests = [
			'scheduler' => [
				'GET' => self::object(['history_before' => $historyCursor]),
				'POST' => self::body(['configuration' => $schedulerPatch, 'paused' => $boolean, 'history_before' => $historyCursor]) + [
					'additionalProperties' => false,
					'oneOf' => [['required' => ['configuration']], ['required' => ['paused']]],
				],
			],
			'accountSettings' => ['POST' => self::body(['values' => $accountPatch], ['values'])],
			'backupPolicy' => ['POST' => self::body(['configuration' => $backupPatch], ['configuration'])],
			'items' => ['GET' => self::object([
				'get' => ['anyOf' => [['enum' => self::STREAMS], ['type' => 'string', 'pattern' => self::STREAM_PATTERN]], 'default' => 'a'],
				'filter' => ['enum' => array_keys(self::FILTERS), 'default' => 'all'],
				'cursor' => ['anyOf' => [$entryId, ['const' => '']]],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => min(PHP_INT_MAX - 1, $preferences['pageSize']['max'] ?? 1),
					'default' => $preferences['pageSize']['default']],
				'search' => ['type' => 'string', 'maxLength' => $readerLimits['max_search_chars']],
			])],
			'article' => ['GET' => self::object(['id' => $entryId], ['id'])],
			'state' => ['POST' => self::body(['id' => $entryId] + $state, ['id']) + $oneState],
			'batch' => ['POST' => self::body(['ids' => ['type' => 'array', 'items' => $entryId, 'minItems' => 1,
				'maxItems' => $apiLimits['maxBatchItems']]] + $state, ['ids']) + $oneState],
			'categories' => ['POST' => self::body(['id' => $integerId, 'name' => $name], ['name'])],
			'feedUpdate' => ['POST' => self::body(['id' => $integerId, 'name' => $name, 'categoryId' => $integerId], ['id'])
				+ ['anyOf' => [['required' => ['name']], ['required' => ['categoryId']]]]],
			'feedBatch' => ['POST' => self::body([
				'ids' => ['type' => 'array', 'items' => $integerId, 'uniqueItems' => true, 'minItems' => 1, 'maxItems' => $apiLimits['maxBatchItems']],
				'changes' => $patch, 'expectedVersions' => ['type' => 'object', 'additionalProperties' => $revision],
			], ['ids', 'changes', 'expectedVersions'])],
			'subscriptionBatch' => ['POST' => self::body(['items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => $apiLimits['maxBatchItems'],
				'items' => ['oneOf' => [
					self::object(['type' => ['const' => 'feed'], 'id' => $integerId, 'expectedVersion' => $revision, 'changes' => $patch],
						['type', 'id', 'changes', 'expectedVersion']) + ['additionalProperties' => false],
					self::object(['type' => ['const' => 'category'], 'id' => $integerId, 'expectedVersion' => $revision,
						'changes' => self::object(['name' => $name], ['name']) + ['additionalProperties' => false]],
						['type', 'id', 'changes', 'expectedVersion']) + ['additionalProperties' => false],
				]]]], ['items'])],
			'rulePreview' => ['POST' => self::body(['rules' => ['type' => 'string'], 'entry' => ['type' => 'object'],
				'explain' => ['type' => 'boolean', 'default' => false], 'updated' => ['type' => 'boolean', 'default' => false],
				'operations' => ['type' => 'array', 'maxItems' => $apiLimits['maxBatchItems'], 'items' => ['type' => 'object']]], ['rules'])],
			'extractionPreview' => ['POST' => self::body(['source' => ['type' => 'string'], 'recipe' => ['type' => 'object'],
				'baseUrl' => ['type' => 'string', 'default' => '']], ['source', 'recipe'])],
			'preferences' => ['POST' => self::body(['preferences' => self::object($preferenceFields) + ['additionalProperties' => false]], ['preferences'])],
			'limits' => ['POST' => self::body(['limits' => self::object($limitFields) + ['additionalProperties' => false, 'minProperties' => 1]], ['limits'])],
			'extensionConfig' => [
				'GET' => self::object(['extension' => ['type' => 'string', 'description' => 'Extension entrypoint; omit to list configurable user extensions']]),
				'POST' => self::body(['extension' => ['type' => 'string'], 'configuration' => ['type' => 'object'],
					'expectedVersion' => ['type' => 'string', 'minLength' => 1]], ['extension', 'configuration', 'expectedVersion']),
			],
			'extensionBatch' => ['POST' => self::body(['items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => $apiLimits['maxBatchItems'],
				'items' => self::object(['extension' => ['type' => 'string'], 'configuration' => ['type' => 'object'],
					'expectedVersion' => ['type' => 'string', 'minLength' => 1]], ['extension', 'configuration', 'expectedVersion'])
					+ ['additionalProperties' => false]]], ['items'])],
		];
		$summaryFields = ['id' => $entryId, 'feedId' => $integerId, 'title' => ['type' => 'string'], 'author' => ['type' => 'string'],
			'date' => ['type' => 'integer'], 'read' => $boolean, 'starred' => $boolean, 'link' => ['type' => 'string']];
		$summary = self::object($summaryFields, ['id', 'feedId', 'title', 'author', 'date', 'read', 'starred', 'link']);
		$extensionDescription = self::object(['extension' => ['type' => 'string'], 'version' => ['type' => 'string'],
			'schema' => ['anyOf' => [['type' => 'object'], ['type' => 'array', 'maxItems' => 0]],
				'description' => 'Plugin-native field specifications; PHP may encode an empty map as []'],
			'values' => ['anyOf' => [['type' => 'object'], ['type' => 'array', 'maxItems' => 0]]], 'errors' => ['type' => ['object', 'array']],
			'redacted' => ['type' => 'array', 'items' => ['type' => 'string']]], ['extension', 'version', 'schema', 'values', 'errors', 'redacted']);
		$feedConfiguration = self::object(['id' => $integerId, 'name' => ['type' => 'string'], 'categoryId' => ['type' => 'integer', 'minimum' => 0],
			'ttl' => ['type' => 'integer', 'minimum' => 0], 'enabled' => $boolean, 'priority' => ['type' => 'integer'],
			'unread' => ['type' => 'integer'], 'error' => $boolean, 'version' => $revision],
			['id', 'name', 'categoryId', 'ttl', 'enabled', 'priority', 'unread', 'error', 'version']);
		$categoryConfiguration = self::object(['id' => $integerId, 'name' => ['type' => 'string'], 'version' => $revision], ['id', 'name', 'version']);
		$feedList = ['type' => 'array', 'items' => $feedConfiguration];
		$categoryList = ['type' => 'array', 'items' => $categoryConfiguration];
		$history = self::object(['id' => $integerId, 'username' => ['type' => 'string'], 'feed_id' => ['type' => 'integer'],
			'kind' => ['type' => 'string'], 'finished' => ['type' => 'integer'], 'outcome' => ['type' => 'string'],
			'duration_ms' => ['type' => 'integer'], 'peak_bytes' => ['type' => 'integer']],
			['id', 'username', 'feed_id', 'kind', 'finished', 'outcome', 'duration_ms', 'peak_bytes']);
		$queueStatus = self::object(['paused' => $boolean, 'pending' => ['type' => 'integer'], 'running' => ['type' => 'integer'],
			'retrying' => ['type' => 'integer'], 'due' => ['type' => 'integer'], 'oldestDue' => ['type' => ['integer', 'null']],
			'history' => ['type' => 'array', 'items' => $history], 'historyNext' => ['type' => ['integer', 'null']]],
			['paused', 'pending', 'running', 'retrying', 'due', 'oldestDue', 'history', 'historyNext']);
		$nullableCount = ['type' => ['integer', 'null'], 'minimum' => 0];
		$archive = self::object(['name' => ['type' => 'string'], 'bytes' => ['type' => 'integer', 'minimum' => 1],
			'modifiedAt' => ['type' => 'integer', 'minimum' => 1], 'active' => $boolean], ['name', 'bytes', 'modifiedAt', 'active']);

		$responses = [
			'capabilities' => ['api' => ['const' => 'freshrss-reader'], 'authentication' => ['const' => 'session'],
				'csrf' => ['const' => 'required_for_post'], 'limits' => ['type' => 'object'], 'pagination' => ['type' => 'object'],
				'capabilities' => ['type' => 'array', 'items' => ['type' => 'string']], 'features' => ['type' => 'object'],
				'batch' => ['type' => 'object'], 'clientApis' => ['type' => 'object']],
			'schema' => ['preferences' => ['type' => 'object'], 'limits' => ['type' => 'object'], 'entryId' => ['type' => 'object'],
				'subscription' => ['type' => 'object'], 'contract' => ['type' => 'object'], 'batch' => ['type' => 'object'],
				'rulePresets' => ['type' => 'object'], 'ruleLimits' => ['type' => 'object'], 'extractionPresets' => ['type' => 'object']],
			'items' => ['items' => ['type' => 'array', 'items' => $summary], 'next' => ['anyOf' => [$entryId, ['type' => 'null']]]],
			'article' => ['article' => self::object($summaryFields + ['content' => ['type' => 'string']],
				['id', 'feedId', 'title', 'author', 'date', 'read', 'starred', 'link', 'content'])],
			'state' => ['id' => $entryId] + $state,
			'batch' => ['ids' => ['type' => 'array', 'items' => $entryId], 'changed' => ['type' => 'integer'], 'atomic' => ['const' => false]],
			'feeds' => ['categories' => $categoryList, 'feeds' => $feedList],
			'categories' => ['category' => ['type' => 'object']],
			'feedUpdate' => ['id' => $integerId, 'updated' => ['type' => 'array', 'items' => ['type' => 'string']]],
			'extensionBatch' => ['atomic' => ['const' => true], 'extensions' => ['type' => 'object', 'additionalProperties' => $extensionDescription]],
			'feedBatch' => ['atomic' => ['const' => true], 'feeds' => $feedList, 'updated' => ['type' => 'array', 'items' => $integerId]],
			'subscriptionBatch' => ['atomic' => ['const' => true], 'feeds' => $feedList, 'categories' => $categoryList],
			'rulePreview' => ['preview' => ['type' => 'object']], 'extractionPreview' => ['preview' => ['type' => 'object']],
			'preferences' => ['preferences' => self::object($preferenceFields), 'schema' => ['type' => 'object']],
			'limits' => ['limits' => self::object($limitFields), 'schema' => ['type' => 'object'], 'preferences' => self::object($preferenceFields)],
			'scheduler' => ['configuration' => self::object($schedulerFields, array_keys($schedulerFields)),
				'schema' => ['type' => 'object'], 'status' => $queueStatus],
			'accountSettings' => ['schema' => self::object(['fields' => ['type' => 'object'], 'atomic' => ['const' => true]], ['fields', 'atomic']),
				'values' => self::object($accountFields, array_keys($accountFields))],
			'backupPolicy' => ['configuration' => self::object($backupFields, ['enabled', 'retention']),
				'schema' => ['type' => 'object'], 'atomic' => ['const' => true]],
			'storage' => ['timestamp' => ['type' => 'integer'],
				'database' => self::object(['type' => ['enum' => ['sqlite', 'mysql', 'pgsql']], 'bytes' => $nullableCount,
					'totalBytes' => $nullableCount], ['type', 'bytes']),
				'articles' => self::object(['total' => $nullableCount, 'read' => $nullableCount, 'unread' => $nullableCount,
					'favourites' => $nullableCount], ['total', 'read', 'unread', 'favourites']),
				'feeds' => $nullableCount,
				'backups' => self::object(['sqliteAvailable' => $boolean, 'bytes' => ['type' => 'integer', 'minimum' => 0],
					'archives' => ['type' => 'array', 'items' => $archive]], ['sqliteAvailable', 'bytes', 'archives'])],
		];
		$endpoints = [];
		foreach (self::routes() as $action => $methods) {
			if ($action === 'index') {
				continue;
			}
			$operations = [];
			foreach ($methods as $method) {
				$fields = $responses[$action] ?? [];
				if ($action === 'categories' && $method === 'GET') {
					$fields = $responses['feeds'];
				}
				$operations[$method] = [
					'input' => $method === 'POST' ? 'json-body' : 'query',
					'request' => $requests[$action][$method] ?? self::object([]),
					'response' => self::object(['version' => ['const' => self::VERSION]] + $fields, ['version', ...array_keys($fields)]),
				];
			}
			$endpoints[$action] = ['url' => '?c=reader&a=' . $action, 'methods' => $methods,
				'administrator' => in_array($action, ['scheduler', 'limits', 'backupPolicy'], true), 'operations' => $operations];
		}
		$singleExtension = self::object(['version' => ['const' => self::VERSION], 'configuration' => $extensionDescription], ['version', 'configuration']);
		$endpoints['extensionConfig']['operations']['GET']['response'] = ['oneOf' => [$singleExtension,
			self::object(['version' => ['const' => self::VERSION], 'configurations' => ['type' => 'array', 'items' => $extensionDescription]],
				['version', 'configurations'])]];
		$endpoints['extensionConfig']['operations']['POST']['response'] = $singleExtension;
		$endpoints['export']['operations']['GET']['response'] = ['type' => 'string'];
		$endpoints['export']['contentType'] = 'text/x-opml; charset=UTF-8';
		return ['format' => 'freshrss-reader-contract', 'version' => self::VERSION, 'schemaDialect' => 'https://json-schema.org/draft/2020-12/schema',
			'authentication' => ['type' => 'session', 'csrfBodyField' => '_csrf', 'csrfMethods' => ['POST']],
			'limits' => $apiLimits, 'endpoints' => $endpoints,
			'error' => self::object(['version' => ['const' => self::VERSION], 'error' => ['type' => 'string'],
				'errorDetails' => self::object(['code' => ['type' => 'string'], 'status' => ['type' => 'integer']], ['code', 'status'])], ['version', 'error']),
			'authenticationErrors' => 'json', 'csrfErrors' => 'json', 'requestValidationErrors' => 'json',
			'preControllerErrorsMayBeHtml' => true];
	}

	/** @param array<string,mixed> $properties
	 * @param list<string> $required
	 * @return array<string,mixed>
	 */
	private static function object(array $properties, array $required = []): array {
		return ['type' => 'object', 'properties' => (object)$properties, 'required' => $required];
	}

	/** @param array<string,mixed> $properties
	 * @param list<string> $required
	 * @return array<string,mixed>
	 */
	private static function body(array $properties, array $required = []): array {
		return self::object(['_csrf' => ['type' => 'string', 'minLength' => 1]] + $properties, ['_csrf', ...$required]);
	}
}
