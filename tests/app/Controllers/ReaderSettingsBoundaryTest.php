<?php
declare(strict_types=1);

use FreshRSS\Services\Reader\ApiContract;

/** HTTP tests exercise real JSON parsing, global CSRF checks and Reader actions using private fixtures. */
final class ReaderSettingsBoundaryTest extends \PHPUnit\Framework\TestCase {
	private string $directory = '';
	private string $url = '';
	/** @var resource|null */
	private $server = null;
	/** @var resource|null */
	private $serverErrors = null;

	#[\Override]
	protected function setUp(): void {
		if (!function_exists('proc_open') || !function_exists('curl_init') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			self::markTestSkipped('Local PHP HTTP server and PDO SQLite required');
		}
		$this->directory = sys_get_temp_dir() . '/freshrss-settings-boundary-' . bin2hex(random_bytes(8));
		mkdir($this->directory . '/users/_', 0700, true);
		mkdir($this->directory . '/tokens', 0700);
		file_put_contents($this->directory . '/config.php', '<?php return ' . var_export([
			'auth_type' => 'form', 'default_user' => 'boundaryowner', 'salt' => 'boundary-system-secret',
			'db' => ['type' => 'sqlite'], 'environment' => 'production',
		], true) . ';');
		foreach (['boundaryowner' => 3, 'boundarymember' => 1] as $user => $articles) {
			mkdir($this->directory . '/users/' . $user, 0700);
			file_put_contents($this->directory . '/users/' . $user . '/config.php', '<?php return ' . var_export([
				'enabled' => true, 'passwordHash' => 'boundary-password-secret', 'apiPasswordHash' => 'boundary-api-secret',
			], true) . ';');
			$database = new PDO('sqlite:' . $this->directory . '/users/' . $user . '/db.sqlite');
			$database->exec('CREATE TABLE entry (is_read INTEGER, is_favorite INTEGER); CREATE TABLE feed (id INTEGER);');
			for ($index = 0; $index < $articles; $index++) {
				$database->exec('INSERT INTO entry VALUES (0, 0)');
			}
			$database = null;
			file_put_contents($this->directory . '/users/' . $user . '/backup-' . $user . '.sqlite', 'private-backup');
		}
		// Authentication is fixed by this test-only router; no test handler is added to the application.
		file_put_contents($this->directory . '/router.php', <<<'PHP'
			<?php
			declare(strict_types=1);
			require getenv('FRESHRSS_BOUNDARY_REPOSITORY') . '/constants.php';
			require LIB_PATH . '/lib_rss.php';
			Minz_Session::init('SettingsBoundary', true);
			$user = $_SERVER['HTTP_X_READER_TEST_USER'] ?? '';
			$_SESSION = ['csrf' => 'boundary-csrf'];
			if (in_array($user, ['boundaryowner', 'boundarymember'], true)) {
				$_SESSION += [Minz_User::CURRENT_USER => $user, 'loginOk' => true, 'passwordHash' => 'boundary-password-secret'];
			}
			FreshRSS_Context::initSystem();
			new FreshRSS();
			(new ReflectionMethod(FreshRSS::class, 'initAuth'))->invoke(null);
			$controller = new FreshRSS_reader_Controller();
			$controller->firstAction();
			$action = Minz_Request::actionName() . 'Action';
			$controller->$action();
			PHP);
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		self::assertIsResource($socket);
		$address = stream_socket_get_name($socket, false);
		self::assertIsString($address);
		fclose($socket);
		$this->url = 'http://' . $address;
		$server = proc_open([PHP_BINARY, '-S', $address, $this->directory . '/router.php'],
			[['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['pipe', 'w']], $pipes,
			env_vars: ['DATA_PATH' => $this->directory, 'FRESHRSS_BOUNDARY_REPOSITORY' => FRESHRSS_PATH, 'FRESHRSS_ENV' => 'production']);
		self::assertIsResource($server);
		$this->server = $server;
		$this->serverErrors = $pipes[2];
		stream_set_blocking($pipes[2], false);
		$deadline = microtime(true) + 3;
		do {
			$connection = @stream_socket_client('tcp://' . $address, $code, $message, 0.1);
			if (is_resource($connection)) {
				fclose($connection);
				return;
			}
			usleep(10000);
		} while (microtime(true) < $deadline);
		self::fail('Private settings HTTP fixture failed to start');
	}

	#[\Override]
	protected function tearDown(): void {
		if (is_resource($this->server)) {
			proc_terminate($this->server);
			proc_close($this->server);
		}
		if (is_resource($this->serverErrors)) {
			fclose($this->serverErrors);
		}
		if ($this->directory !== '') {
			recursive_unlink($this->directory);
		}
	}

	/** @param array<string,mixed>|null $body
	 * @param array<string,string> $query
	 * @param non-empty-string $method
	 * @return array{status:int,headers:list<string>,body:array<string,mixed>}
	 */
	private function request(string $action, string $method = 'GET', ?array $body = null,
		string $user = 'boundarymember', array $query = []): array {
		$headers = ['Accept: application/json', 'X-Reader-Test-User: ' . $user];
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
		}
		$curl = curl_init($this->url . '/?' . http_build_query(['c' => 'reader', 'a' => $action] + $query));
		self::assertNotFalse($curl);
		$received = [];
		curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
			CURLOPT_POSTFIELDS => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
			CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3,
			CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $header) use (&$received): int {
				$received[] = trim($header);
				return strlen($header);
			}]);
		$response = curl_exec($curl);
		self::assertIsString($response);
		$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		self::assertNotEmpty($received);
		try {
			$json = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			$diagnostic = is_resource($this->serverErrors) ? (stream_get_contents($this->serverErrors) ?: '') : '';
			self::fail('Fixture did not return JSON (HTTP ' . $status . '): ' . substr($response, 0, 500) . ' ' . $diagnostic);
		}
		self::assertIsArray($json);
		self::assertTrue(is_array_keys_string($json));
		self::assertSame(ApiContract::VERSION, $json['version'] ?? null);
		self::assertContains('Cache-Control: private, no-store', $received);
		self::assertContains('X-Content-Type-Options: nosniff', $received);
		return ['status' => $status, 'headers' => $received, 'body' => $json];
	}

	public function testAnonymousRequestsExposeNoSettingsOrTools(): void {
		foreach (['accountSettings', 'storage', 'scheduler', 'backupPolicy'] as $action) {
			foreach (['GET', 'POST'] as $method) {
				$response = $this->request($action, $method, $method === 'POST' ? ['_csrf' => 'boundary-csrf'] : null, '');
				self::assertSame(403, $response['status']);
				self::assertSame('authentication_required', $response['body']['error']);
			}
		}
	}

	public function testAccountMutationsRequireSessionCsrfInTheBody(): void {
		$path = $this->directory . '/users/boundarymember/config.php';
		$before = file_get_contents($path);
		foreach ([[], ['_csrf' => 'invalid'], ['_csrf' => ['boundary-csrf']]] as $csrf) {
			$response = $this->request('accountSettings', 'POST', $csrf + ['values' => ['posts_per_page' => 77]]);
			self::assertSame(403, $response['status']);
			self::assertSame('csrf_invalid', $response['body']['error']);
		}
		$response = $this->request('accountSettings', 'POST', ['values' => ['posts_per_page' => 77]],
			query: ['_csrf' => 'boundary-csrf']);
		self::assertSame(403, $response['status']);
		self::assertSame($before, file_get_contents($path));
	}

	public function testRoutesRejectUnsupportedMethodsWithoutMutation(): void {
		$response = $this->request('storage', 'POST', ['_csrf' => 'boundary-csrf']);
		self::assertSame(405, $response['status']);
		self::assertContains('Allow: GET', $response['headers']);
		$response = $this->request('accountSettings', 'DELETE');
		self::assertSame(405, $response['status']);
		self::assertContains('Allow: GET, POST', $response['headers']);
	}

	public function testAccountChangesCannotTargetAnotherAccountOrGrantPermissions(): void {
		$owner = $this->directory . '/users/boundaryowner/config.php';
		$before = file_get_contents($owner);
		$response = $this->request('accountSettings', 'POST', ['_csrf' => 'boundary-csrf', 'values' => ['posts_per_page' => 77]],
			query: ['user' => 'boundaryowner']);
		self::assertSame(200, $response['status']);
		self::assertIsArray($response['body']['values']);
		self::assertSame(77, $response['body']['values']['posts_per_page']);
		self::assertSame($before, file_get_contents($owner));
		foreach (['is_admin' => true, 'passwordHash' => 'replacement', 'extensions_enabled' => []] as $key => $value) {
			$response = $this->request('accountSettings', 'POST', ['_csrf' => 'boundary-csrf', 'values' => [$key => $value]]);
			self::assertSame(400, $response['status']);
			self::assertSame('invalid_field', $response['body']['error']);
		}
		$response = $this->request('accountSettings');
		self::assertSame(200, $response['status']);
		self::assertStringNotContainsString('boundary-password-secret', json_encode($response['body'], JSON_THROW_ON_ERROR));
		self::assertStringNotContainsString('boundary-api-secret', json_encode($response['body'], JSON_THROW_ON_ERROR));
	}

	public function testSchedulerRequiresAdministratorForReadingAndEveryWrite(): void {
		foreach ([['GET', null], ['POST', ['_csrf' => 'boundary-csrf', 'paused' => true]],
			['POST', ['_csrf' => 'boundary-csrf', 'configuration' => ['enabled' => true]]]] as [$method, $body]) {
			$response = $this->request('scheduler', $method, $body);
			self::assertSame(403, $response['status']);
			self::assertSame('administrator_required', $response['body']['error']);
		}
		self::assertDirectoryDoesNotExist($this->directory . '/scheduler');
	}

	public function testAdministratorCanConfigureAndPauseWithAnExactTypedPayload(): void {
		foreach ([[], ['_csrf' => 'invalid']] as $csrf) {
			$response = $this->request('scheduler', 'POST', $csrf + ['paused' => true], 'boundaryowner');
			self::assertSame(403, $response['status']);
			self::assertSame('csrf_invalid', $response['body']['error']);
		}
		self::assertDirectoryDoesNotExist($this->directory . '/scheduler');
		$response = $this->request('scheduler', 'POST', ['_csrf' => 'boundary-csrf', 'configuration' => ['workers' => 4]], 'boundaryowner');
		self::assertSame(200, $response['status']);
		self::assertIsArray($response['body']['configuration']);
		self::assertSame(4, $response['body']['configuration']['workers']);
		$response = $this->request('scheduler', 'POST', ['_csrf' => 'boundary-csrf', 'paused' => true], 'boundaryowner');
		self::assertSame(200, $response['status']);
		self::assertIsArray($response['body']['status']);
		self::assertTrue($response['body']['status']['paused']);
		foreach ([['paused' => 'false'], ['paused' => false, 'configuration' => null],
			['paused' => false, 'configuration' => ['workers' => 2]], ['paused' => false, 'unknown' => true]] as $body) {
			$response = $this->request('scheduler', 'POST', ['_csrf' => 'boundary-csrf'] + $body, 'boundaryowner');
			self::assertSame(400, $response['status']);
		}
		$response = $this->request('scheduler', user: 'boundaryowner');
		self::assertIsArray($response['body']['status']);
		self::assertIsArray($response['body']['configuration']);
		self::assertTrue($response['body']['status']['paused']);
		self::assertSame(4, $response['body']['configuration']['workers']);
	}

	public function testInvalidHistoryCursorDoesNotCommitSchedulerChanges(): void {
		$response = $this->request('scheduler', 'POST', ['_csrf' => 'boundary-csrf', 'paused' => true], 'boundaryowner',
			['history_before' => 'invalid']);
		self::assertSame(400, $response['status']);
		$response = $this->request('scheduler', user: 'boundaryowner');
		self::assertIsArray($response['body']['status']);
		self::assertFalse($response['body']['status']['paused']);
	}

	public function testStorageUsesTheCurrentAccountAndHidesSystemPathsAndTotals(): void {
		$response = $this->request('storage', query: ['user' => 'boundaryowner']);
		self::assertSame(200, $response['status']);
		self::assertIsArray($response['body']['articles']);
		self::assertIsArray($response['body']['database']);
		self::assertIsArray($response['body']['backups']);
		self::assertIsArray($response['body']['backups']['archives']);
		self::assertSame(1, $response['body']['articles']['total']);
		self::assertArrayNotHasKey('totalBytes', $response['body']['database']);
		$names = array_column($response['body']['backups']['archives'], 'name');
		sort($names);
		self::assertSame(['backup-boundarymember.sqlite', 'db.sqlite'], $names);
		self::assertStringNotContainsString($this->directory, json_encode($response['body'], JSON_THROW_ON_ERROR));
		$response = $this->request('storage', user: 'boundaryowner');
		self::assertSame(200, $response['status']);
		self::assertIsArray($response['body']['articles']);
		self::assertIsArray($response['body']['database']);
		self::assertSame(3, $response['body']['articles']['total']);
		self::assertArrayHasKey('totalBytes', $response['body']['database']);
	}

	public function testBackupPolicyRequiresAdministratorAndBodyCsrfWithoutExportingFiles(): void {
		foreach (['GET', 'POST'] as $method) {
			$response = $this->request('backupPolicy', $method,
				$method === 'POST' ? ['_csrf' => 'boundary-csrf', 'configuration' => ['enabled' => true]] : null);
			self::assertSame(403, $response['status']);
			self::assertSame('administrator_required', $response['body']['error']);
		}
		$before = file_get_contents($this->directory . '/config.php');
		$response = $this->request('backupPolicy', 'POST', ['configuration' => ['enabled' => true]], 'boundaryowner');
		self::assertSame(403, $response['status']);
		self::assertSame('csrf_invalid', $response['body']['error']);
		foreach ([['retention' => 0], ['retention' => '7'], ['enabled' => 1], ['path' => '/private'], []] as $values) {
			$response = $this->request('backupPolicy', 'POST', ['_csrf' => 'boundary-csrf', 'configuration' => $values], 'boundaryowner');
			self::assertSame(400, $response['status']);
		}
		self::assertSame($before, file_get_contents($this->directory . '/config.php'));
		$response = $this->request('backupPolicy', 'POST', ['_csrf' => 'boundary-csrf', 'configuration' => ['enabled' => true]], 'boundaryowner');
		self::assertSame(200, $response['status']);
		self::assertTrue($response['body']['atomic']);
		self::assertSame(['enabled' => true, 'retention' => 7], $response['body']['configuration']);
		$response = $this->request('backupPolicy', 'POST', ['_csrf' => 'boundary-csrf', 'configuration' => ['retention' => 2]], 'boundaryowner');
		self::assertSame(200, $response['status']);
		self::assertSame(['enabled' => true, 'retention' => 2], $response['body']['configuration']);
		self::assertCount(2, glob($this->directory . '/users/boundaryowner/*.sqlite') ?: []);
		self::assertDirectoryDoesNotExist($this->directory . '/scheduler');
	}
}
