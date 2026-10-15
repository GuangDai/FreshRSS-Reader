<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Reader;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Services\Reader\ApiContract;
use FreshRSS\Services\Reader\EntryPresenter;
use FreshRSS\Services\Reader\SettingsService;
use FreshRSS\Services\Refresh\SchedulerConfiguration;
use FreshRSS_reader_Controller;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ApiContractTest extends TestCase {
	private static function path(mixed $value, string ...$keys): mixed {
		foreach ($keys as $key) {
			self::assertIsArray($value);
			self::assertArrayHasKey($key, $value);
			$value = $value[$key];
		}
		return $value;
	}

	public function testContractUsesLiveLimitsAndSerialisesEmptyObjectsCorrectly(): void {
		$contract = ApiContract::contract(['max_page_size' => 7, 'max_search_chars' => 19],
			['maxBatchItems' => 3, 'maxRequestBytes' => 1234]);
		$json = json_encode($contract, JSON_THROW_ON_ERROR);
		self::assertStringContainsString('"properties":{}', $json);
		$decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);
		self::assertSame(7, self::path($decoded, 'endpoints', 'items', 'operations', 'GET', 'request', 'properties', 'limit', 'maximum'));
		self::assertSame(19, self::path($decoded, 'endpoints', 'items', 'operations', 'GET', 'request', 'properties', 'search', 'maxLength'));
		self::assertSame(3, self::path($decoded, 'endpoints', 'batch', 'operations', 'POST', 'request', 'properties', 'ids', 'maxItems'));
		self::assertSame(['_csrf', 'ids'], self::path($decoded, 'endpoints', 'batch', 'operations', 'POST', 'request', 'required'));
	}

	public function testAnonymousReadingContractAdvertisesOnlyPublicGetOperations(): void {
		$contract = json_decode(json_encode(ApiContract::contract(['max_page_size' => 7, 'max_search_chars' => 19],
			['maxBatchItems' => 3, 'maxRequestBytes' => 1234], readOnly: true), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame('anonymous', self::path($contract, 'authentication', 'type'));
		self::assertNull(self::path($contract, 'authentication', 'csrfBodyField'));
		self::assertSame([], self::path($contract, 'authentication', 'csrfMethods'));
		$endpoints = self::path($contract, 'endpoints');
		self::assertIsArray($endpoints);
		self::assertSame(array_values(array_diff(ApiContract::PUBLIC_READ_ACTIONS, ['index'])), array_keys($endpoints));
		foreach ($endpoints as $endpoint) {
			self::assertSame(['GET'], self::path($endpoint, 'methods'));
			$operations = self::path($endpoint, 'operations');
			self::assertIsArray($operations);
			self::assertSame(['GET'], array_keys($operations));
		}
		self::assertSame('anonymous', self::path($contract, 'endpoints', 'capabilities', 'operations', 'GET',
			'response', 'properties', 'authentication', 'const'));
		self::assertTrue(self::path($contract, 'endpoints', 'schema', 'operations', 'GET', 'response', 'properties', 'readOnly', 'const'));
	}

	public function testRouteTableCoversEveryReaderAction(): void {
		$actions = [];
		foreach ((new ReflectionClass(FreshRSS_reader_Controller::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() === FreshRSS_reader_Controller::class && str_ends_with($method->getName(), 'Action') &&
				$method->getName() !== 'firstAction') {
				$actions[] = substr($method->getName(), 0, -6);
			}
		}
		$routes = array_keys(ApiContract::routes());
		sort($actions);
		sort($routes);
		self::assertSame($actions, $routes);
		self::assertSame(['POST'], ApiContract::routes()['batch']);
		self::assertSame(['GET'], ApiContract::routes()['article']);
	}

	public function testSchemaUsesTheEntryValidatorConstants(): void {
		$schema = ApiContract::entryIdSchema();
		self::assertSame(EntryPresenter::ENTRY_ID_MAX, $schema['x-maximumDecimal']);
		self::assertSame(EntryPresenter::ENTRY_ID_PATTERN, $schema['pattern']);
		self::assertSame(EntryPresenter::ENTRY_ID_MAX, EntryPresenter::entryId(EntryPresenter::ENTRY_ID_MAX));
		self::assertNull(EntryPresenter::entryId('9223372036854775808'));
	}
	public function testEndCursorDoesNotBypassOtherRequestValidation(): void {
		try {
			ApiContract::listing(['cursor' => '1', 'limit' => 11], ['max_page_size' => 10, 'max_search_chars' => 20]);
			self::fail('A terminal cursor must not make invalid settings appear successful');
		} catch (ApiException $exception) {
			self::assertSame('page_size_out_of_range', $exception->getMessage());
		}
		$request = ApiContract::listing(['cursor' => '1', 'limit' => 10], ['max_page_size' => 10, 'max_search_chars' => 20]);
		self::assertTrue($request['exhausted']);
		self::assertSame('0', $request['idMax']);
	}

	public function testListingPreservesLargeStringIdsAndRejectsArrayQueryParameters(): void {
		$request = ApiContract::listing(['cursor' => '9007199254740993', 'get' => 'f_12', 'search' => '标题'],
			['max_page_size' => 10, 'max_search_chars' => 2]);
		self::assertSame('9007199254740992', $request['idMax']);
		self::assertSame(12, $request['id']);
		self::assertSame('f', $request['type']);
		foreach (['get' => 'invalid_stream', 'filter' => 'invalid_filter', 'cursor' => 'invalid_cursor', 'search' => 'invalid_search'] as $field => $error) {
			try {
				ApiContract::listing([$field => ['unexpected']], ['max_page_size' => 10, 'max_search_chars' => 20]);
				self::fail('Array query parameter must be rejected');
			} catch (ApiException $exception) {
				self::assertSame($error, $exception->getMessage());
			}
		}
	}

	public function testLegacyLabelStreamsAndReadingStatesRemainAvailableFromHomeLinks(): void {
		foreach (['t_42' => ['t', 42], 'T' => ['T', 0], 'Z' => ['Z', 0]] as $stream => [$type, $id]) {
			$request = ApiContract::listing(['get' => $stream, 'state' => '96'], ['max_page_size' => 10, 'max_search_chars' => 20]);
			self::assertSame($type, $request['type']);
			self::assertSame($id, $request['id']);
			self::assertSame(96, $request['state']);
		}
		foreach ([1, '2', 3, '4', 5, 6, 8, 32, 64, 96] as $state) {
			$request = ApiContract::listing(['state' => $state], ['max_page_size' => 10, 'max_search_chars' => 20]);
			self::assertSame((int)$state, $request['state']);
		}
		foreach (['', '0', '-1', '01', '1e2', '999999999999999999999999', 16, 112, true, ['1']] as $value) {
			try {
				ApiContract::listing(['state' => $value], ['max_page_size' => 10, 'max_search_chars' => 20]);
				self::fail('An invalid legacy state must not change the reading query');
			} catch (ApiException $exception) {
				self::assertSame('invalid_state', $exception->getMessage());
			}
		}
	}

	public function testSchedulerHistoryCursorIsStrictAndDoesNotOverflow(): void {
		self::assertNull(ApiContract::historyCursor(null));
		self::assertSame(12, ApiContract::historyCursor('12'));
		foreach (['', '0', '01', '-1', '1e2', '999999999999999999999999', true, ['1']] as $value) {
			try {
				ApiContract::historyCursor($value);
				self::fail('Invalid history cursor must be rejected');
			} catch (ApiException $exception) {
				self::assertSame('invalid_history_cursor', $exception->getMessage());
			}
		}
	}

	public function testAccountSettingsContractPublishesOnlyEditableFieldsAndPartialPatchTypes(): void {
		$contract = json_decode(json_encode(ApiContract::contract(['max_page_size' => 20, 'max_search_chars' => 100],
			['maxBatchItems' => 100, 'maxRequestBytes' => 1024]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		$endpoint = self::path($contract, 'endpoints', 'accountSettings');
		self::assertSame(['GET', 'POST'], self::path($endpoint, 'methods'));
		self::assertFalse(self::path($endpoint, 'administrator'));
		$request = self::path($endpoint, 'operations', 'POST', 'request');
		self::assertSame(['_csrf', 'values'], self::path($request, 'required'));
		$patch = self::path($request, 'properties', 'values');
		self::assertFalse(self::path($patch, 'additionalProperties'));
		self::assertSame(1, self::path($patch, 'minProperties'));
		$fields = self::path($patch, 'properties');
		self::assertIsArray($fields);
		self::assertSame(array_keys(SettingsService::fields()), array_keys($fields));
		self::assertArrayNotHasKey('passwordHash', $fields);
		self::assertArrayNotHasKey('is_admin', $fields);
		self::assertSame('boolean', self::path($fields, 'mark_when.article', 'type'));
		self::assertSame('string', self::path($fields, 'filters.read', 'items', 'type'));
		self::assertSame([['type' => 'integer', 'minimum' => 0], ['const' => false]], self::path($fields, 'archiving.keep_max', 'anyOf'));
		foreach (['GET', 'POST'] as $method) {
			$properties = self::path($endpoint, 'operations', $method, 'response', 'properties');
			self::assertSame(['version', 'schema', 'values'], self::path($endpoint, 'operations', $method, 'response', 'required'));
			self::assertTrue(self::path($properties, 'schema', 'properties', 'atomic', 'const'));
			self::assertSame($fields, self::path($properties, 'values', 'properties'));
		}
	}

	public function testSchedulerContractRequiresCsrfAndExactlyOneAdministratorMutation(): void {
		$contract = json_decode(json_encode(ApiContract::contract(['max_page_size' => 20, 'max_search_chars' => 100],
			['maxBatchItems' => 100, 'maxRequestBytes' => 1024]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		$endpoint = self::path($contract, 'endpoints', 'scheduler');
		self::assertTrue(self::path($endpoint, 'administrator'));
		self::assertSame(['GET', 'POST'], self::path($endpoint, 'methods'));
		$request = self::path($endpoint, 'operations', 'POST', 'request');
		self::assertSame(['_csrf'], self::path($request, 'required'));
		self::assertSame([['required' => ['configuration']], ['required' => ['paused']]], self::path($request, 'oneOf'));
		self::assertFalse(self::path($request, 'additionalProperties'));
		self::assertSame('boolean', self::path($request, 'properties', 'paused', 'type'));
		$fields = self::path($request, 'properties', 'configuration', 'properties');
		self::assertIsArray($fields);
		self::assertSame(array_keys(SchedulerConfiguration::schema()), array_keys($fields));
		self::assertSame('boolean', self::path($fields, 'enabled', 'type'));
		self::assertSame(2147483647, self::path($fields, 'busy_timeout_ms', 'maximum'));
		self::assertArrayNotHasKey('salt', $fields);
		self::assertArrayNotHasKey('paused', $fields);
	}

	public function testAccountRetentionSchemaMatchesTheServiceCompositeDurationSyntax(): void {
		$contract = json_decode(json_encode(ApiContract::contract(['max_page_size' => 20, 'max_search_chars' => 100],
			['maxBatchItems' => 100, 'maxRequestBytes' => 1024]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		$fields = self::path($contract, 'endpoints', 'accountSettings', 'operations', 'POST', 'request', 'properties', 'values', 'properties');
		$pattern = self::path($fields, 'archiving.keep_period', 'anyOf', '0', 'pattern');
		self::assertIsString($pattern);
		self::assertSame(SettingsService::DURATION_PATTERN, $pattern);
		foreach (['P0D', 'P1DT6H', 'PT30M', 'PT45S', 'P1Y2M3W4DT5H6M7S'] as $duration) {
			self::assertSame(1, preg_match('/' . $pattern . '/D', $duration), $duration);
			self::assertSame($duration, SettingsService::validate(['archiving.keep_period' => $duration])['archiving.keep_period']);
		}
		foreach (['P', 'PT', 'P1DT', 'P-1D', 'PT0.5S', 'P1D1Y', 'PT1M2H', 'PT1H1H'] as $duration) {
			self::assertSame(0, preg_match('/' . $pattern . '/D', $duration), $duration);
		}
	}

	public function testStorageContractKeepsAccountReadAccessAndOptionalAdministratorTotals(): void {
		$contract = json_decode(json_encode(ApiContract::contract(['max_page_size' => 20, 'max_search_chars' => 100],
			['maxBatchItems' => 100, 'maxRequestBytes' => 1024]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		$endpoint = self::path($contract, 'endpoints', 'storage');
		self::assertSame(['GET'], self::path($endpoint, 'methods'));
		self::assertFalse(self::path($endpoint, 'administrator'));
		$response = self::path($endpoint, 'operations', 'GET', 'response', 'properties');
		self::assertSame(['type', 'bytes'], self::path($response, 'database', 'required'));
		self::assertSame(['integer', 'null'], self::path($response, 'database', 'properties', 'totalBytes', 'type'));
		self::assertSame(['name', 'bytes', 'modifiedAt', 'active'], self::path($response, 'backups', 'properties', 'archives', 'items', 'required'));
		$archiveFields = self::path($response, 'backups', 'properties', 'archives', 'items', 'properties');
		self::assertIsArray($archiveFields);
		self::assertArrayNotHasKey('path', $archiveFields);
	}

	public function testAutomaticBackupPolicyIsAnAdministratorPatchWithoutExportExecutionControls(): void {
		$contract = json_decode(json_encode(ApiContract::contract(['max_page_size' => 20, 'max_search_chars' => 100],
			['maxBatchItems' => 100, 'maxRequestBytes' => 1024]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		$endpoint = self::path($contract, 'endpoints', 'backupPolicy');
		self::assertSame(['GET', 'POST'], self::path($endpoint, 'methods'));
		self::assertTrue(self::path($endpoint, 'administrator'));
		$request = self::path($endpoint, 'operations', 'POST', 'request');
		self::assertSame(['_csrf', 'configuration'], self::path($request, 'required'));
		$patch = self::path($request, 'properties', 'configuration');
		self::assertFalse(self::path($patch, 'additionalProperties'));
		self::assertSame(1, self::path($patch, 'minProperties'));
		$fields = self::path($patch, 'properties');
		self::assertIsArray($fields);
		self::assertSame(['enabled', 'retention'], array_keys($fields));
		self::assertSame('boolean', self::path($fields, 'enabled', 'type'));
		self::assertSame(1, self::path($fields, 'retention', 'minimum'));
		foreach (['GET', 'POST'] as $method) {
			self::assertSame(['version', 'configuration', 'schema', 'atomic'], self::path($endpoint, 'operations', $method, 'response', 'required'));
			self::assertTrue(self::path($endpoint, 'operations', $method, 'response', 'properties', 'atomic', 'const'));
		}
	}

}
