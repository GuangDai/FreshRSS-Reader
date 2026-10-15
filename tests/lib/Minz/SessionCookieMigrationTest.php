<?php
declare(strict_types=1);

/** Exercise real session storage, login and JSON writes through the public PHP entry point. */
final class SessionCookieMigrationTest extends \PHPUnit\Framework\TestCase {
	private string $directory = '';
	private string $url = '';
	private string $passwordHash = '';
	/** @var resource|null */
	private $server = null;
	/** @var resource|null */
	private $serverErrors = null;
	/** @var array<string,array{name:string,value:string,path:string}> */
	private array $cookies = [];

	#[\Override]
	protected function setUp(): void {
		if (!function_exists('proc_open') || !function_exists('curl_init') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			self::markTestSkipped('Local PHP HTTP server and PDO SQLite required');
		}
		$this->directory = sys_get_temp_dir() . '/freshrss-session-cookie-' . bin2hex(random_bytes(8));
		foreach (['users/_', 'users/cookieowner', 'cache', 'tokens', 'sessions'] as $path) {
			mkdir($this->directory . '/' . $path, 0700, true);
		}
		$this->passwordHash = password_hash('private-cookie-fixture', PASSWORD_BCRYPT, ['cost' => 4]);
		file_put_contents($this->directory . '/config.php', '<?php return ' . var_export([
			'auth_type' => 'form', 'default_user' => 'cookieowner', 'salt' => 'private-session-cookie-fixture',
			'db' => ['type' => 'sqlite'], 'environment' => 'production', 'language' => 'en',
		], true) . ';');
		file_put_contents($this->directory . '/users/cookieowner/config.php', '<?php return ' . var_export([
			'enabled' => true, 'passwordHash' => $this->passwordHash, 'language' => 'en',
		], true) . ';');
		file_put_contents($this->directory . '/applied_migrations.txt', '');
		require APP_PATH . '/SQL/install.sql.sqlite.php';
		$sql = $GLOBALS['SQL_CREATE_TABLES'];
		self::assertIsString($sql);
		$database = new PDO('sqlite:' . $this->directory . '/users/cookieowner/db.sqlite');
		$database->exec($sql);
		$database = null;
		// The router only adapts the local HTTP server; authentication and dispatch remain unchanged.
		file_put_contents($this->directory . '/router.php', <<<'PHP'
			<?php
			declare(strict_types=1);
			ini_set('session.save_path', __DIR__ . '/sessions');
			$_SERVER['SCRIPT_NAME'] = '/i/index.php';
			unset($_SERVER['PATH_INFO'], $_SERVER['ORIG_PATH_INFO']);
			require getenv('FRESHRSS_SESSION_TEST_REPOSITORY') . '/p/i/index.php';
			PHP);
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		self::assertIsResource($socket);
		$address = stream_socket_get_name($socket, false);
		self::assertIsString($address);
		fclose($socket);
		$this->url = 'http://' . $address . '/i/';
		$server = proc_open([PHP_BINARY, '-S', $address, $this->directory . '/router.php'],
			[['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['pipe', 'w']], $pipes,
			env_vars: ['DATA_PATH' => $this->directory, 'FRESHRSS_SESSION_TEST_REPOSITORY' => FRESHRSS_PATH, 'FRESHRSS_ENV' => 'production']);
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
		self::fail('Private session cookie HTTP fixture failed to start');
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

	/**
	 * Preserve the exact Path distinction browsers make between /i/ and /i.
	 * libcurl's built-in cookie store normalises these paths and hides this migration failure.
	 */
	private function acceptCookie(string $header): void {
		$parts = array_map('trim', explode(';', $header));
		[$name, $value] = explode('=', array_shift($parts) ?: '=', 2);
		$path = '/i';
		$expired = false;
		foreach ($parts as $part) {
			[$key, $attribute] = array_pad(explode('=', $part, 2), 2, '');
			if (strtolower($key) === 'path') {
				$path = $attribute;
			} elseif (strtolower($key) === 'expires') {
				$expires = strtotime($attribute);
				$expired = $expires !== false && $expires < time();
			} elseif (strtolower($key) === 'max-age' && $attribute === '0') {
				$expired = true;
			}
		}
		$key = $name . '|' . $path;
		if ($expired) {
			unset($this->cookies[$key]);
		} else {
			$this->cookies[$key] = ['name' => $name, 'value' => $value, 'path' => $path];
		}
	}

	/** @param array<string,mixed>|null $body
	 * @return array{status:int,body:string,headers:array<string,list<string>>}
	 */
	private function request(string $query = '', ?array $body = null, bool $json = false): array {
		$cookies = array_values($this->cookies);
		usort($cookies, static fn(array $a, array $b): int => strlen($b['path']) <=> strlen($a['path']));
		$headers = ['Cookie: ' . implode('; ', array_map(static fn(array $cookie): string => $cookie['name'] . '=' . $cookie['value'], $cookies))];
		if ($body !== null) {
			$headers[] = 'Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded');
		}
		$curl = curl_init($this->url . $query);
		self::assertNotFalse($curl);
		$received = [];
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => $headers,
			CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $header) use (&$received): int {
				$parts = explode(':', $header, 2);
				if (count($parts) === 2) {
					$received[strtolower(trim($parts[0]))][] = trim($parts[1]);
				}
				return strlen($header);
			},
		]);
		if ($body !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $json ? json_encode($body, JSON_THROW_ON_ERROR) : http_build_query($body));
		}
		$response = curl_exec($curl);
		self::assertIsString($response, curl_error($curl));
		foreach ($received['set-cookie'] ?? [] as $cookie) {
			$this->acceptCookie($cookie);
		}
		return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $response, 'headers' => $received];
	}

	/** @return array{csrf:string,preferences:array<string,mixed>} */
	private function homepage(): array {
		$response = $this->request();
		$diagnostic = is_resource($this->serverErrors) ? (stream_get_contents($this->serverErrors) ?: '') : '';
		self::assertSame(200, $response['status'], $diagnostic);
		self::assertSame(1, preg_match('/id="reader-bootstrap"[^>]*>(.*?)<\/script>/s', $response['body'], $matches));
		$bootstrap = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($bootstrap);
		self::assertIsString($bootstrap['csrf'] ?? null);
		self::assertIsArray($bootstrap['preferences'] ?? null);
		self::assertTrue(is_array_keys_string($bootstrap['preferences']));
		return ['csrf' => $bootstrap['csrf'], 'preferences' => $bootstrap['preferences']];
	}

	/** @return array{csrf:string,preferences:array<string,mixed>} */
	private function login(): array {
		$form = $this->request('?c=auth&a=login');
		self::assertSame(200, $form['status']);
		self::assertSame(1, preg_match('/name="_csrf"\s+value="([^"]+)"/', $form['body'], $matches));
		$nonce = json_decode($this->request('?c=javascript&a=nonce&user=cookieowner')['body'], true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($nonce);
		self::assertIsString($nonce['nonce'] ?? null);
		$challenge = password_hash($this->passwordHash . $nonce['nonce'], PASSWORD_BCRYPT, ['cost' => 4]);
		$response = $this->request('?c=auth&a=login', [
			'_csrf' => $matches[1], 'username' => 'cookieowner', 'challenge' => $challenge, 'keep_logged_in' => '1',
		]);
		self::assertSame(302, $response['status']);
		self::assertArrayHasKey('FreshRSS|/i', $this->cookies);
		self::assertArrayHasKey('FreshRSS_login|/i', $this->cookies);
		return $this->homepage();
	}

	/** @param array{csrf:string,preferences:array<string,mixed>} $bootstrap */
	private function assertPreferenceSave(array $bootstrap): void {
		$response = $this->request('?c=reader&a=preferences', ['_csrf' => $bootstrap['csrf'], 'preferences' => $bootstrap['preferences']], true);
		self::assertSame(200, $response['status']);
		$json = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($json);
		self::assertSame($bootstrap['preferences'], $json['preferences'] ?? null);
	}

	public function testRealSessionAcceptsBodyCsrfAndRejectsMissingInvalidOrQueryTokens(): void {
		$bootstrap = $this->login();
		$this->assertPreferenceSave($bootstrap);
		$before = file_get_contents($this->directory . '/users/cookieowner/config.php');
		foreach ([[], ['_csrf' => 'invalid'], ['_csrf' => [$bootstrap['csrf']]]] as $token) {
			$response = $this->request('?c=reader&a=preferences', $token + ['preferences' => $bootstrap['preferences']], true);
			self::assertSame(403, $response['status']);
			$json = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
			self::assertIsArray($json);
			self::assertSame('csrf_invalid', $json['error'] ?? null);
		}
		$response = $this->request('?c=reader&a=preferences&_csrf=' . urlencode($bootstrap['csrf']), ['preferences' => $bootstrap['preferences']], true);
		self::assertSame(403, $response['status']);
		self::assertSame($before, file_get_contents($this->directory . '/users/cookieowner/config.php'));
		self::assertSame($bootstrap['csrf'], $this->homepage()['csrf']);
		$this->assertPreferenceSave($bootstrap);
	}

	public function testRememberedLoginRemovesTheLegacyCookieThatWouldShadowTheRotatedSession(): void {
		$this->login();
		$this->cookies['FreshRSS|/i/'] = ['name' => 'FreshRSS', 'value' => 'expiredlegacysession123456789', 'path' => '/i/'];
		$bootstrap = $this->homepage();
		self::assertArrayNotHasKey('FreshRSS|/i/', $this->cookies, 'The old /i/ cookie would shadow the rotated /i session on every request');
		self::assertArrayHasKey('FreshRSS|/i', $this->cookies);
		$this->assertPreferenceSave($bootstrap);
		self::assertSame($bootstrap['csrf'], $this->homepage()['csrf']);
		$this->assertPreferenceSave($bootstrap);
	}

	public function testAnAlreadyOpenPageCannotWriteWithItsPreviousTokenAfterSessionRestoration(): void {
		$previous = $this->login();
		$before = file_get_contents($this->directory . '/users/cookieowner/config.php');
		$this->cookies['FreshRSS|/i/'] = ['name' => 'FreshRSS', 'value' => 'expiredlegacysession123456789', 'path' => '/i/'];
		$response = $this->request('?c=reader&a=preferences', ['_csrf' => $previous['csrf'], 'preferences' => $previous['preferences']], true);
		self::assertSame(403, $response['status']);
		$json = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($json);
		self::assertSame('csrf_invalid', $json['error'] ?? null);
		self::assertSame($before, file_get_contents($this->directory . '/users/cookieowner/config.php'));
		self::assertArrayNotHasKey('FreshRSS|/i/', $this->cookies);
		$restored = $this->homepage();
		self::assertNotSame($previous['csrf'], $restored['csrf']);
		$this->assertPreferenceSave($restored);
	}
}
