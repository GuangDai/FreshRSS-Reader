<?php
declare(strict_types=1);

use FreshRSS\Services\Reader\ApiContract;

/** Exercise the public homepage and legacy reading links through the real dispatcher. */
final class HomeReadingTest extends \PHPUnit\Framework\TestCase {
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
		$this->directory = sys_get_temp_dir() . '/freshrss-home-reading-' . bin2hex(random_bytes(8));
		mkdir($this->directory . '/users/_', 0700, true);
		mkdir($this->directory . '/users/homeowner', 0700);
		mkdir($this->directory . '/cache', 0700);
		mkdir($this->directory . '/tokens', 0700);
		$this->setSystemPreferences([]);
		$this->setUserPreferences([]);
		require APP_PATH . '/SQL/install.sql.sqlite.php';
		$sql = $GLOBALS['SQL_CREATE_TABLES'];
		self::assertIsString($sql);
		$database = new PDO('sqlite:' . $this->directory . '/users/homeowner/db.sqlite');
		$database->exec($sql);
		$database->exec("INSERT INTO feed (id, url, name, category) VALUES (42, 'https://example.com/feed', 'Fixture feed', 1)");
		$database = null;
		// Only session identity is simulated. Routing, authentication and views use the application unchanged.
		file_put_contents($this->directory . '/router.php', <<<'PHP'
			<?php
			declare(strict_types=1);
			$repository = getenv('FRESHRSS_HOME_TEST_REPOSITORY');
			$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
			$_SERVER['SCRIPT_NAME'] = $path === '/' ? '/index.php' : '/i/index.php';
			unset($_SERVER['PATH_INFO'], $_SERVER['ORIG_PATH_INFO']);
			if ($path === '/') {
				require $repository . '/p/index.php';
				return;
			}
			require $repository . '/constants.php';
			require LIB_PATH . '/lib_rss.php';
			Minz_Session::init('HomeReading', true);
			$_SESSION = ['csrf' => 'home-fixture-csrf'];
			if (($_SERVER['HTTP_X_HOME_TEST_USER'] ?? '') === 'homeowner') {
				$_SESSION += [Minz_User::CURRENT_USER => 'homeowner', 'loginOk' => true, 'passwordHash' => 'home-fixture-password'];
			}
			FreshRSS_Context::initSystem();
			$application = new FreshRSS();
			$application->init();
			$application->run();
			PHP);
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		self::assertIsResource($socket);
		$address = stream_socket_get_name($socket, false);
		self::assertIsString($address);
		fclose($socket);
		$this->url = 'http://' . $address;
		$server = proc_open([PHP_BINARY, '-S', $address, $this->directory . '/router.php'],
			[['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['pipe', 'w']], $pipes,
			env_vars: ['DATA_PATH' => $this->directory, 'FRESHRSS_HOME_TEST_REPOSITORY' => FRESHRSS_PATH, 'FRESHRSS_ENV' => 'production']);
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
		self::fail('Private homepage HTTP fixture failed to start');
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

	/** @param non-empty-string $method
	 * @param array<string,mixed>|null $body
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private function request(string $path, bool $authenticated = true, string $method = 'GET', ?array $body = null): array {
		$curl = curl_init($this->url . $path);
		self::assertNotFalse($curl);
		$headers = [];
		$requestHeaders = ['X-Home-Test-User: ' . ($authenticated ? 'homeowner' : '')];
		if ($body !== null) {
			$requestHeaders[] = 'Content-Type: application/json';
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
		}
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
			CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $requestHeaders,
			CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $header) use (&$headers): int {
				$parts = explode(':', $header, 2);
				if (count($parts) === 2) {
					$headers[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
				return strlen($header);
			},
		]);
		$body = curl_exec($curl);
		self::assertIsString($body, curl_error($curl));
		return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
	}

	/** @param array{status:int,headers:array<string,string>,body:string} $response */
	private function assertReadingPage(array $response): void {
		$diagnostic = is_resource($this->serverErrors) ? (stream_get_contents($this->serverErrors) ?: '') : '';
		self::assertSame(200, $response['status'], $diagnostic);
		self::assertStringStartsWith('text/html', $response['headers']['content-type'] ?? '');
		self::assertMatchesRegularExpression('/class="[^"]*\breader-shell\b/', $response['body']);
		self::assertStringContainsString('id="reader-bootstrap"', $response['body']);
		self::assertDoesNotMatchRegularExpression('/id=["\']stream["\']/', $response['body']);
		self::assertStringNotContainsString('home-fixture-password', $response['body']);
	}

	/** @param array<string,mixed> $preferences */
	private function setSystemPreferences(array $preferences): void {
		file_put_contents($this->directory . '/config.php', '<?php return ' . var_export($preferences + [
			'auth_type' => 'form', 'default_user' => 'homeowner', 'salt' => 'home-fixture-secret',
			'db' => ['type' => 'sqlite'], 'environment' => 'production', 'language' => 'en',
		], true) . ';');
	}

	/** @param array<string,mixed> $preferences */
	private function setUserPreferences(array $preferences): void {
		file_put_contents($this->directory . '/users/homeowner/config.php', '<?php return ' . var_export($preferences + [
			'enabled' => true, 'passwordHash' => 'home-fixture-password', 'language' => 'en',
		], true) . ';');
	}

	/** @return array<string,mixed> */
	private function initialQuery(string $path = '/i/'): array {
		$response = $this->request($path);
		$bootstrap = $this->bootstrap($response);
		$query = $bootstrap['initialQuery'] ?? null;
		self::assertIsArray($query);
		self::assertTrue(is_array_keys_string($query));
		return $query;
	}

	/** @param array{status:int,headers:array<string,string>,body:string} $response
	 * @return array<string,mixed>
	 */
	private function bootstrap(array $response): array {
		$this->assertReadingPage($response);
		self::assertSame(1, preg_match('/<script\b[^>]*\bid="reader-bootstrap"[^>]*>(.*?)<\/script>/s', $response['body'], $matches));
		$bootstrap = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($bootstrap);
		self::assertTrue(is_array_keys_string($bootstrap));
		return $bootstrap;
	}

	/** @param array{status:int,headers:array<string,string>,body:string} $response
	 * @return array<string,mixed>
	 */
	private function jsonResponse(array $response, int $status = 200): array {
		self::assertSame($status, $response['status'], $response['body']);
		self::assertStringStartsWith('application/json', $response['headers']['content-type'] ?? '');
		self::assertSame('private, no-store', $response['headers']['cache-control'] ?? null);
		$json = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($json);
		self::assertTrue(is_array_keys_string($json));
		self::assertSame(ApiContract::VERSION, $json['version'] ?? null);
		return $json;
	}

	private function seedArticle(string $username = 'homeowner', string $title = 'Public fixture article'): void {
		$database = new PDO('sqlite:' . $this->directory . '/users/' . $username . '/db.sqlite');
		$sql = $GLOBALS['SQL_CREATE_TABLES'];
		self::assertIsString($sql);
		$database->exec($sql);
		$database->exec("INSERT OR IGNORE INTO feed (id, url, name, category) VALUES (42, 'https://example.com/feed', 'Fixture feed', 1)");
		$insert = $database->prepare('INSERT INTO entry (id, guid, title, content, link, date, id_feed, is_read, is_favorite)
			VALUES (1000001, :guid, :title, :content, :link, 1700000000, 42, 0, 0)');
		self::assertNotFalse($insert);
		$insert->execute(['guid' => $username . '-fixture', 'title' => $title, 'content' => '<p>' . $title . ' body</p>',
			'link' => 'https://example.com/' . $username . '/article']);
		$database->exec('UPDATE feed SET cache_nbEntries = 1, cache_nbUnreads = 1 WHERE id = 42');
	}

	/** @return array<string,mixed> */
	private function accountSnapshot(): array {
		$result = ['systemConfig' => file_get_contents($this->directory . '/config.php'),
			'userConfig' => file_get_contents($this->directory . '/users/homeowner/config.php')];
		$database = new PDO('sqlite:' . $this->directory . '/users/homeowner/db.sqlite');
		foreach (['category', 'feed', 'entry', 'tag', 'entrytag'] as $table) {
			$rows = $database->query('SELECT * FROM ' . $table . ' ORDER BY rowid');
			self::assertNotFalse($rows);
			$result[$table] = $rows->fetchAll(PDO::FETCH_ASSOC);
		}
		return $result;
	}

	public function testPublicRootPreservesReadingQueriesWhenRedirectingToTheHomepage(): void {
		$query = ['get' => 'f_42', 'filter' => 'unread', 'search' => '中文 & RSS', 'state' => '2'];
		$response = $this->request('/?' . http_build_query($query), false);
		self::assertSame(302, $response['status'], json_encode($response, JSON_THROW_ON_ERROR));
		$location = $response['headers']['location'] ?? '';
		self::assertSame('/i/', parse_url($location, PHP_URL_PATH));
		parse_str((string)parse_url($location, PHP_URL_QUERY), $actual);
		foreach ($query as $key => $value) {
			self::assertSame($value, $actual[$key] ?? null, 'Lost homepage query: ' . $key);
		}
		$this->assertReadingPage($this->request($location));
	}

	public function testHomepageAndLegacyReadingModesRenderTheOnlyReadingInterface(): void {
		foreach (['/i/', '/i/?c=index', '/i/?c=index&a=index', '/i/?c=index&a=normal',
			'/i/?c=index&a=reader', '/i/?c=index&a=global', '/i/?c=reader'] as $path) {
			$this->assertReadingPage($this->request($path));
		}
	}

	public function testStoredClassicViewPreferencesCannotRestoreTheClassicInterface(): void {
		foreach (['normal', 'reader', 'global'] as $mode) {
			$this->setUserPreferences(['view_mode' => $mode]);
			$this->assertReadingPage($this->request('/i/'));
		}
	}

	public function testHomepageKeepsTheAccountsDefaultReadingFilter(): void {
		foreach (['adaptive' => 'all', 'unread' => 'unread'] as $preference => $filter) {
			$this->setUserPreferences(['default_view' => $preference]);
			self::assertSame(['get' => 'a', 'filter' => $filter, 'search' => ''], $this->initialQuery());
		}
	}

	public function testUnreadScopesContinueBeyondFiftyWithoutSkippingWhenTheCursorArticleBecomesRead(): void {
		$database = new PDO('sqlite:' . $this->directory . '/users/homeowner/db.sqlite');
		$database->exec("INSERT INTO category (id, name) VALUES (2, 'Other category')");
		$database->exec("INSERT INTO feed (id, url, name, category) VALUES
			(43, 'https://example.com/other', 'Other feed', 2), (44, 'https://example.com/same', 'Same category feed', 1)");
		$insert = $database->prepare('INSERT INTO entry (id, guid, title, content, link, date, id_feed, is_read, is_favorite)
			VALUES (?, ?, ?, ?, ?, 1700000000, ?, ?, 0)');
		self::assertNotFalse($insert);
		$expected = ['a' => [], 'c_1' => [], 'f_42' => []];
		for ($index = 0; $index < 360; $index++) {
			$id = (string)(1000360 - $index);
			$feed = 42 + $index % 3;
			$read = $index % 5 === 0;
			$insert->execute([$id, 'continuous-' . $id, 'Continuous article ' . $id, '<p>Body</p>', '', $feed, (int)$read]);
			if (!$read) {
				$expected['a'][] = $id;
				if ($feed !== 43) {
					$expected['c_1'][] = $id;
				}
				if ($feed === 42) {
					$expected['f_42'][] = $id;
				}
			}
		}
		foreach ($expected as $scope => $ids) {
			$database->exec('UPDATE entry SET is_read=CASE WHEN (1000360-id)%5=0 THEN 1 ELSE 0 END');
			$database->exec('UPDATE feed SET cache_nbEntries=(SELECT COUNT(*) FROM entry WHERE id_feed=feed.id),
				cache_nbUnreads=(SELECT COUNT(*) FROM entry WHERE id_feed=feed.id AND is_read=0)');
			$cursor = '';
			$actual = [];
			$pages = 0;
			do {
				$page = $this->jsonResponse($this->request('/i/?' . http_build_query([
					'c' => 'reader', 'a' => 'items', 'get' => $scope, 'filter' => 'unread', 'cursor' => $cursor, 'limit' => 50,
				])));
				self::assertIsArray($page['items']);
				foreach ($page['items'] as $item) {
					self::assertIsArray($item);
					self::assertFalse($item['read']);
					self::assertIsString($item['id']);
					self::assertArrayNotHasKey('content', $item);
					$actual[] = $item['id'];
				}
				self::assertArrayHasKey('next', $page);
				$cursor = $page['next'];
				self::assertTrue(is_string($cursor) || $cursor === null);
				if ($cursor !== null) {
					$changed = $this->jsonResponse($this->request('/i/?c=reader&a=state', true, 'POST',
						['_csrf' => 'home-fixture-csrf', 'id' => $cursor, 'read' => true]));
					self::assertTrue($changed['read']);
				}
				$pages++;
				self::assertLessThan(10, $pages, 'Cursor pagination must make forward progress');
			} while ($cursor !== null);
			self::assertGreaterThan(1, $pages);
			self::assertSame($ids, $actual, 'Unread scope lost or duplicated articles: ' . $scope);
		}
	}

	public function testLegacyReadAndFavouriteStatesSurviveTheHomepageTransition(): void {
		self::assertSame(['get' => 'a', 'filter' => 'all', 'search' => '', 'state' => 1], $this->initialQuery('/i/?state=1'));
		$this->setUserPreferences(['default_view' => 'unread_or_favorite']);
		self::assertSame(['get' => 'a', 'filter' => 'all', 'search' => '', 'state' => 96], $this->initialQuery());
	}

	public function testExplicitReadingFilterOverridesTheLegacyStateAndAccountDefault(): void {
		$this->setUserPreferences(['default_view' => 'unread_or_favorite']);
		self::assertSame(['get' => 'f_42', 'filter' => 'unread', 'search' => 'RSS'],
			$this->initialQuery('/i/?get=f_42&filter=unread&state=1&search=RSS'));
	}

	public function testAnonymousHtmlEntrypointsOfferLoginRatherThanAnApiError(): void {
		foreach (['/i/', '/i/?c=index&a=normal', '/i/?c=index&a=reader', '/i/?c=index&a=global', '/i/?c=reader'] as $path) {
			$response = $this->request($path, false);
			self::assertSame(200, $response['status'], $path);
			self::assertStringStartsWith('text/html', $response['headers']['content-type'] ?? '', $path);
			self::assertStringContainsString('name="username"', $response['body'], $path);
			self::assertStringContainsString('name="original_request"', $response['body'], $path);
			self::assertStringNotContainsString('authentication_required', $response['body'], $path);
			self::assertStringNotContainsString('class="reader-shell"', $response['body'], $path);
		}
	}

	public function testAnonymousJsonEndpointsKeepTheirAuthenticationBoundary(): void {
		foreach (['items', 'capabilities'] as $action) {
			$response = $this->request('/i/?c=reader&a=' . $action, false);
			self::assertSame(403, $response['status']);
			self::assertStringStartsWith('application/json', $response['headers']['content-type'] ?? '');
			self::assertSame('private, no-store', $response['headers']['cache-control'] ?? null);
			$json = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
			self::assertIsArray($json);
			self::assertSame(ApiContract::VERSION, $json['version'] ?? null);
			self::assertSame('authentication_required', $json['error'] ?? null);
			self::assertStringNotContainsString('name="username"', $response['body']);
		}
	}

	public function testAnonymousReadingUsesTheEnabledDefaultAccountWithoutGrantingWriteAccess(): void {
		$this->setSystemPreferences(['allow_anonymous' => true]);
		$this->setUserPreferences(['mark_when' => ['article' => true, 'site' => true]]);
		$this->seedArticle();
		$before = $this->accountSnapshot();
		foreach (['/i/', '/i/?user=homeowner', '/i/?c=index&a=normal', '/i/?c=index&a=global'] as $path) {
			$bootstrap = $this->bootstrap($this->request($path, false));
			self::assertTrue($bootstrap['readOnly'] ?? null);
			self::assertSame('', $bootstrap['csrf'] ?? null);
			self::assertFalse($bootstrap['autoMarkArticle'] ?? null);
			self::assertFalse($bootstrap['autoMarkSite'] ?? null);
			self::assertFalse($bootstrap['canManageLimits'] ?? null);
		}
		$capabilities = $this->jsonResponse($this->request('/i/?c=reader&a=capabilities', false));
		self::assertTrue($capabilities['readOnly'] ?? null);
		self::assertSame('anonymous', $capabilities['authentication'] ?? null);
		self::assertSame(['capabilities', 'schema', 'feeds', 'items', 'article'], $capabilities['capabilities'] ?? null);
		$schema = $this->jsonResponse($this->request('/i/?c=reader&a=schema', false));
		self::assertTrue($schema['readOnly'] ?? null);
		self::assertArrayNotHasKey('preferences', $schema);
		self::assertArrayNotHasKey('subscription', $schema);
		self::assertArrayNotHasKey('rulePresets', $schema);
		self::assertArrayNotHasKey('extractionPresets', $schema);
		self::assertIsArray($schema['contract'] ?? null);
		self::assertIsArray($schema['contract']['endpoints'] ?? null);
		self::assertSame(['capabilities', 'schema', 'feeds', 'items', 'article'], array_keys($schema['contract']['endpoints']));
		foreach ($schema['contract']['endpoints'] as $endpoint) {
			self::assertIsArray($endpoint);
			self::assertSame(['GET'], $endpoint['methods'] ?? null);
			self::assertIsArray($endpoint['operations'] ?? null);
			self::assertSame(['GET'], array_keys($endpoint['operations']));
		}
		$feeds = $this->jsonResponse($this->request('/i/?c=reader&a=feeds&user=homeowner', false));
		self::assertIsArray($feeds['feeds'] ?? null);
		self::assertIsArray($feeds['feeds'][0] ?? null);
		self::assertSame(42, $feeds['feeds'][0]['id'] ?? null);
		$items = $this->jsonResponse($this->request('/i/?c=reader&a=items&filter=all', false));
		self::assertIsArray($items['items'] ?? null);
		self::assertIsArray($items['items'][0] ?? null);
		self::assertSame('1000001', $items['items'][0]['id'] ?? null);
		self::assertSame('Public fixture article', $items['items'][0]['title'] ?? null);
		$article = $this->jsonResponse($this->request('/i/?c=reader&a=article&id=1000001', false));
		self::assertIsArray($article['article'] ?? null);
		self::assertSame('Public fixture article', $article['article']['title'] ?? null);
		self::assertIsString($article['article']['content'] ?? null);
		self::assertStringContainsString('Public fixture article body', $article['article']['content']);
		self::assertFalse($article['article']['read'] ?? null);
		self::assertSame($before, $this->accountSnapshot());
	}

	public function testAnonymousReadingDoesNotRemoveAuthenticatedControls(): void {
		$this->setSystemPreferences(['allow_anonymous' => true]);
		$this->setUserPreferences(['mark_when' => ['article' => true, 'site' => true]]);
		$bootstrap = $this->bootstrap($this->request('/i/'));
		self::assertFalse($bootstrap['readOnly'] ?? null);
		self::assertSame('home-fixture-csrf', $bootstrap['csrf'] ?? null);
		self::assertTrue($bootstrap['autoMarkArticle'] ?? null);
		self::assertTrue($bootstrap['autoMarkSite'] ?? null);
		self::assertTrue($bootstrap['canManageLimits'] ?? null);
	}

	public function testAnonymousPolicyNeverExposesSettingsOrAllowsAnyPost(): void {
		$this->setSystemPreferences(['allow_anonymous' => true]);
		$this->seedArticle();
		$before = $this->accountSnapshot();
		$public = ['index', 'capabilities', 'schema', 'feeds', 'items', 'article'];
		foreach (array_keys(ApiContract::routes()) as $action) {
			$path = '/i/?c=reader&a=' . $action;
			if (!in_array($action, $public, true)) {
				$json = $this->jsonResponse($this->request($path, false), 403);
				self::assertSame('authentication_required', $json['error'] ?? null, $action);
			}
			$response = $this->request($path, false, 'POST', [
				'_csrf' => 'home-fixture-csrf', 'id' => '1000001', 'ids' => ['1000001'], 'read' => true,
				'values' => ['posts_per_page' => 77], 'preferences' => ['pageSize' => 77],
				'configuration' => ['enabled' => true], 'paused' => true, 'name' => 'Anonymous mutation',
			]);
			if ($action === 'index') {
				self::assertSame(403, $response['status']);
				self::assertStringNotContainsString('id="reader-bootstrap"', $response['body']);
			} else {
				$json = $this->jsonResponse($response, 403);
				self::assertSame('authentication_required', $json['error'] ?? null, $action . ' POST');
			}
		}
		self::assertSame($before, $this->accountSnapshot());
		self::assertDirectoryDoesNotExist($this->directory . '/scheduler');
	}

	public function testRssTokensCannotEnableAnonymousReadingWhenThePolicyIsDisabled(): void {
		$this->setSystemPreferences(['allow_anonymous' => false]);
		$this->setUserPreferences(['token' => 'home-rss-token']);
		$this->seedArticle();
		$query = '&user=homeowner&token=home-rss-token';
		$page = $this->request('/i/?c=reader' . $query, false);
		self::assertSame(403, $page['status']);
		self::assertStringNotContainsString('id="reader-bootstrap"', $page['body']);
		foreach (['capabilities', 'schema', 'feeds', 'items', 'article'] as $action) {
			$json = $this->jsonResponse($this->request('/i/?c=reader&a=' . $action . '&id=1000001' . $query, false), 403);
			self::assertSame('authentication_required', $json['error'] ?? null);
		}
	}

	public function testExistingRssTokensStillAuthorizeRssWithoutEnablingAnonymousReading(): void {
		$this->setSystemPreferences(['allow_anonymous' => false]);
		$this->setUserPreferences(['token' => 'home-rss-token']);
		$this->seedArticle();
		$response = $this->request('/i/?a=rss&user=homeowner&token=home-rss-token', false);
		self::assertSame(200, $response['status'], $response['body']);
		self::assertStringStartsWith('application/rss+xml', $response['headers']['content-type'] ?? '');
		$document = new DOMDocument();
		self::assertTrue($document->loadXML($response['body']));
		self::assertSame('rss', $document->documentElement?->tagName);
		self::assertStringContainsString('Public fixture article', $response['body']);
		self::assertSame(403, $this->request('/i/?a=rss&user=homeowner&token=invalid-token', false)['status']);
	}

	public function testAnonymousSettingsLinksShowLoginInsteadOfAccountSettings(): void {
		$this->setSystemPreferences(['allow_anonymous' => true]);
		foreach (['/i/?view=settings', '/i/?c=reader&view=settings'] as $path) {
			$page = $this->request($path, false);
			self::assertSame(200, $page['status']);
			self::assertStringContainsString('name="username"', $page['body']);
			self::assertStringNotContainsString('id="reader-bootstrap"', $page['body']);
		}
	}

	public function testAnonymousPolicyRequiresAnEnabledDefaultAccount(): void {
		$this->setSystemPreferences(['allow_anonymous' => true]);
		$this->setUserPreferences(['enabled' => false]);
		$this->seedArticle();
		foreach (['capabilities', 'schema', 'feeds', 'items', 'article'] as $action) {
			$json = $this->jsonResponse($this->request('/i/?c=reader&a=' . $action . '&id=1000001', false), 403);
			self::assertSame('authentication_required', $json['error'] ?? null);
		}
		$page = $this->request('/i/', false);
		self::assertSame(403, $page['status']);
		self::assertStringNotContainsString('id="reader-bootstrap"', $page['body']);
	}

	public function testAnonymousDefaultAccountPolicyDoesNotExposeAnotherUsersRssTokenAccount(): void {
		$this->setSystemPreferences(['allow_anonymous' => true]);
		mkdir($this->directory . '/users/privateowner', 0700);
		file_put_contents($this->directory . '/users/privateowner/config.php', '<?php return ' . var_export([
			'enabled' => true, 'passwordHash' => 'private-password', 'token' => 'private-rss-token', 'language' => 'en',
		], true) . ';');
		$this->seedArticle('privateowner', 'Private article must remain hidden');
		foreach (['&user=privateowner', '&user=privateowner&token=private-rss-token'] as $query) {
			foreach (['capabilities', 'schema', 'feeds', 'items', 'article'] as $action) {
				$response = $this->request('/i/?c=reader&a=' . $action . '&id=1000001' . $query, false);
				$json = $this->jsonResponse($response, 403);
				self::assertSame('authentication_required', $json['error'] ?? null);
				self::assertStringNotContainsString('Private article must remain hidden', $response['body']);
			}
			$page = $this->request('/i/?c=reader' . $query, false);
			self::assertSame(403, $page['status']);
			self::assertStringNotContainsString('id="reader-bootstrap"', $page['body']);
		}
	}

	public function testLoginKeepsTheRequestedHomepageFilterAndSearch(): void {
		$query = ['get' => 'f_42', 'filter' => 'unread', 'search' => '中文 & RSS'];
		$response = $this->request('/i/?' . http_build_query($query), false);
		self::assertSame(200, $response['status']);
		self::assertSame(1, preg_match('/name="original_request" value="([^"]*)"/', $response['body'], $matches));
		$original = Minz_Url::unserialize(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
		self::assertSame('index', $original['c'] ?? 'index');
		self::assertSame('index', $original['a'] ?? 'index');
		foreach ($query as $key => $value) {
			self::assertSame($value, $original['params'][$key] ?? null, 'Lost login return query: ' . $key);
		}
	}

	public function testAuthenticatedLoginRedirectsToTheUnifiedHomepage(): void {
		$response = $this->request('/i/?c=auth&a=login');
		self::assertSame(302, $response['status']);
		$location = $response['headers']['location'] ?? '';
		self::assertSame('/i/', parse_url($location, PHP_URL_PATH));
		parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
		self::assertSame('index', $query['c'] ?? 'index');
		self::assertSame('index', $query['a'] ?? 'index');
		$this->assertReadingPage($this->request($location));
	}
}
