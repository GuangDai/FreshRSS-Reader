<?php
declare(strict_types=1);

final class httpRuntimeTest extends \PHPUnit\Framework\TestCase {
	/** @var array<string,mixed> */
	private array $configuration = [];
	/** @var resource|null */
	private $server = null;
	/** @var resource|null */
	private $serverErrors = null;
	private string $url = '';
	private string $counter = '';

	#[\Override]
	protected function setUp(): void {
		FreshRSS_Context::initSystem();
		foreach (['limits', 'curl_options', 'internal_host_allowlist'] as $key) {
			$this->configuration[$key] = FreshRSS_Context::systemConf()->$key;
		}
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
		if ($this->counter !== '') {
			@unlink($this->counter);
		}
		foreach ($this->configuration as $key => $value) {
			FreshRSS_Context::systemConf()->__set($key, $value);
		}
	}

	private function startServer(): void {
		$counter = tempnam(sys_get_temp_dir(), 'http-counter-');
		self::assertIsString($counter);
		$this->counter = $counter;
		$deadline = microtime(true) + 10; // The full suite may start several PHP processes concurrently.
		$diagnostic = '';
		for ($launch = 0; $launch < 3; $launch++) {
			$socket = stream_socket_server('tcp://127.0.0.1:0');
			self::assertIsResource($socket);
			$address = stream_socket_get_name($socket, false);
			self::assertIsString($address);
			$this->url = 'http://' . $address;
			fclose($socket);
			$server = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/http-runtime.php'],
				[['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['pipe', 'w']], $pipes,
				env_vars: ['FRESHRSS_HTTP_TEST_COUNTER' => $counter]);
			self::assertIsResource($server);
			$this->server = $server;
			$this->serverErrors = $pipes[2];
			stream_set_blocking($pipes[2], false);
			$diagnostic = '';
			do {
				$diagnostic .= stream_get_contents($pipes[2]) ?: '';
				$status = proc_get_status($server);
				if (!$status['running']) {
					break;
				}
				// A TCP connection alone could reach a different process that stole the briefly unreserved port.
				if (str_contains($diagnostic, 'Development Server') && str_contains($diagnostic, 'started')) {
					$connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
					if (is_resource($connection)) {
						fclose($connection);
						FreshRSS_Context::systemConf()->internal_host_allowlist = [$address];
						return;
					}
				}
				usleep(10000);
			} while (microtime(true) < $deadline);
			if ($status['running'] || !str_contains($diagnostic, 'Address already in use') || microtime(true) >= $deadline) {
				break;
			}
			// Retry only a confirmed ephemeral-port collision, not a PHP startup/configuration error.
			fclose($pipes[2]);
			$this->serverErrors = null;
			proc_close($server);
			$this->server = null;
		}
		self::fail('Local HTTP fixture did not start: ' . trim($diagnostic));
	}

	public function testBodyLimitRejectsDecodedDataWithoutCachingPartialBody(): void {
		$this->startServer();
		$limits = FreshRSS_Context::systemConf()->limits;
		$limits['http_max_response_bytes'] = 32;
		FreshRSS_Context::systemConf()->limits = $limits;
		$cache = tempnam(sys_get_temp_dir(), 'http-runtime-');
		self::assertIsString($cache);
		file_put_contents($cache, 'previous');
		touch($cache, 1);
		try {
			$result = FreshRSS_http_Util::httpGet($this->url . '/large', $cache, 'json');
			self::assertTrue($result['fail']);
			self::assertSame('', $result['body']);
			self::assertStringContainsString('http_max_response_bytes', $result['error']);
			self::assertSame('previous', file_get_contents($cache));
		} finally {
			unlink($cache);
			@unlink(dirname($cache) . '/.' . basename($cache) . '.lock');
		}
	}

	public function testOversizedCacheIsSkippedAndZeroBodyLimitAllowsLargeResponse(): void {
		$this->startServer();
		$limits = FreshRSS_Context::systemConf()->limits;
		$limits['http_max_response_bytes'] = 32;
		FreshRSS_Context::systemConf()->limits = $limits;
		$cache = tempnam(sys_get_temp_dir(), 'http-runtime-');
		self::assertIsString($cache);
		file_put_contents($cache, str_repeat('x', 64));
		try {
			$result = FreshRSS_http_Util::httpGet($this->url . '/small', $cache, 'json');
			self::assertFalse($result['fail']);
			self::assertSame('ok', $result['body']);
			self::assertSame(200, $result['status']);
			$limits['http_max_response_bytes'] = 0;
			FreshRSS_Context::systemConf()->limits = $limits;
			$result = FreshRSS_http_Util::httpGet($this->url . '/large', type: 'json');
			self::assertFalse($result['fail']);
			self::assertSame(4096, strlen($result['body']));
		} finally {
			unlink($cache);
			@unlink(dirname($cache) . '/.' . basename($cache) . '.lock');
		}
	}

	public function testSimplePieHonoursCallerRedirectLimitAndDeclaredSizeLimit(): void {
		$this->startServer();
		$limits = FreshRSS_Context::systemConf()->limits;
		$limits['http_max_response_bytes'] = 32;
		FreshRSS_Context::systemConf()->limits = $limits;
		$fetch = new FreshRSS_SimplePieFetch($this->url . '/declared');
		self::assertFalse($fetch->success);
		self::assertNotEmpty($fetch->error);
		$fetch = new FreshRSS_SimplePieFetch($this->url . '/loop', redirects: 0);
		self::assertSame(0, $fetch->redirects);
	}

	public function testConcurrentWorkersRefreshOneCacheOnce(): void {
		$this->startServer();
		$cache = tempnam(sys_get_temp_dir(), 'http-runtime-');
		self::assertIsString($cache);
		file_put_contents($cache, 'old complete response');
		touch($cache, 1);
		$processes = [];
		try {
			for ($i = 0; $i < 4; $i++) {
				$process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/http-client.php', $this->url . '/slow', $cache],
					[['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
				self::assertIsResource($process);
				$processes[] = [$process, $pipes];
			}
			// A worker is downloading, but the previous complete inode remains readable.
			usleep(50000);
			self::assertSame('old complete response', file_get_contents($cache));
			$statuses = [];
			foreach ($processes as [$process, $pipes]) {
				$json = stream_get_contents($pipes[1]);
				$error = stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				self::assertSame(0, proc_close($process), is_string($error) ? $error : '');
				self::assertIsString($json);
				$result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
				self::assertIsArray($result);
				self::assertFalse($result['fail']);
				self::assertSame(str_repeat('complete response.', 4096), $result['body']);
				$statuses[] = $result['status'];
			}
			$processes = [];
			sort($statuses);
			self::assertSame([-200, -200, -200, 200], $statuses);
			self::assertSame('1', file_get_contents($this->counter));
			self::assertSame(str_repeat('complete response.', 4096), file_get_contents($cache));
		} finally {
			foreach ($processes as [$process, $pipes]) {
				if (is_resource($process)) {
					proc_terminate($process);
					proc_close($process);
				}
				foreach ($pipes as $pipe) {
					if (is_resource($pipe)) {
						fclose($pipe);
					}
				}
			}
			@unlink($cache);
			@unlink(dirname($cache) . '/.' . basename($cache) . '.lock');
		}
	}

	public function testFailedPublicationLockPreservesExistingCache(): void {
		$this->startServer();
		$cache = tempnam(sys_get_temp_dir(), 'http-runtime-');
		self::assertIsString($cache);
		file_put_contents($cache, 'previous response');
		touch($cache, 1);
		$lock = dirname($cache) . '/.' . basename($cache) . '.lock';
		mkdir($lock); // An unusable lock path must not permit an unlocked publication.
		try {
			$result = FreshRSS_http_Util::httpGet($this->url . '/small', $cache, 'json');
			self::assertFalse($result['fail']);
			self::assertSame('ok', $result['body']);
			self::assertSame('previous response', file_get_contents($cache));
			clearstatcache(true, $cache);
			self::assertSame(1, filemtime($cache));
		} finally {
			unlink($cache);
			rmdir($lock);
		}
	}

	public function testNotModifiedUsesExistingBodyAndErrorsDoNotRefreshCache(): void {
		$this->startServer();
		$cache = tempnam(sys_get_temp_dir(), 'http-runtime-');
		self::assertIsString($cache);
		file_put_contents($cache, '0');
		touch($cache, 1);
		try {
			$result = FreshRSS_http_Util::httpGet($this->url . '/not-modified', $cache, 'json');
			self::assertFalse($result['fail']);
			self::assertSame(304, $result['status']);
			self::assertSame('0', $result['body']);
			$result = FreshRSS_http_Util::httpGet($this->url . '/not-modified', $cache, 'json');
			self::assertSame(-200, $result['status']);
			self::assertSame('0', $result['body']);
			touch($cache, 1);
			$result = FreshRSS_http_Util::httpGet($this->url . '/failed', $cache, 'json');
			self::assertTrue($result['fail']);
			self::assertSame('HTTP 500', $result['error']);
			self::assertSame('0', file_get_contents($cache));
			clearstatcache(true, $cache);
			self::assertSame(1, filemtime($cache));
			$result = FreshRSS_http_Util::httpGet($this->url . '/not-modified', type: 'json');
			self::assertTrue($result['fail']);
			self::assertSame(304, $result['status']);
			self::assertStringContainsString('without a usable cached response', $result['error']);
		} finally {
			@unlink($cache);
			@unlink(dirname($cache) . '/.' . basename($cache) . '.lock');
		}
	}

	public function testHeaderLimitAndRedirectFailureAreExplicit(): void {
		$this->startServer();
		$limits = FreshRSS_Context::systemConf()->limits;
		$limits['http_max_header_bytes'] = 32;
		FreshRSS_Context::systemConf()->limits = $limits;
		$result = FreshRSS_http_Util::httpGet($this->url . '/headers', type: 'json');
		self::assertTrue($result['fail']);
		self::assertStringContainsString('http_max_header_bytes', $result['error']);
		$limits['http_max_header_bytes'] = 0;
		FreshRSS_Context::systemConf()->limits = $limits;
		$result = FreshRSS_http_Util::httpGet($this->url . '/loop', type: 'json', curl_options: [CURLOPT_MAXREDIRS => 1]);
		self::assertTrue($result['fail']);
		self::assertSame('Too many redirects', $result['error']);
		self::assertSame(1, $result['redirect_count']);
		self::assertSame('', $result['body']);
	}

	public function testGlobalCredentialsAreRemovedAcrossOriginsAndAcceptIsPreserved(): void {
		$this->startServer();
		$port = parse_url($this->url, PHP_URL_PORT);
		FreshRSS_Context::systemConf()->internal_host_allowlist = ['127.0.0.1:' . $port, 'localhost:' . $port];
		FreshRSS_Context::systemConf()->__set('curl_options', [
			CURLOPT_USERPWD => 'private:secret',
			CURLOPT_COOKIE => 'private=secret',
			CURLOPT_HTTPHEADER => ['Authorization: Bearer secret', 'X-Test: retained'],
		]);
		$result = FreshRSS_http_Util::httpGet($this->url . '/redirect', type: 'json');
		self::assertFalse($result['fail'], $result['error']);
		$headers = json_decode($result['body'], true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($headers);
		self::assertArrayNotHasKey('Authorization', $headers);
		self::assertArrayNotHasKey('Cookie', $headers);
		self::assertSame('retained', $headers['X-Test']);
		self::assertIsString($headers['Accept']);
		self::assertStringContainsString('application/json', $headers['Accept']);
	}

	public function testRetryAfterRespectsShortSettingsAndCannotBeShortened(): void {
		$limits = FreshRSS_Context::systemConf()->limits;
		$limits['retry_after_default'] = 2;
		$limits['retry_after_max'] = 5;
		FreshRSS_Context::systemConf()->limits = $limits;
		$url = 'http://127.0.0.1/retry-after-' . uniqid();
		$method = new ReflectionMethod(FreshRSS_http_Util::class, 'getRetryAfterFile');
		$file = $method->invoke(null, $url, '');
		self::assertIsString($file);
		try {
			$before = time();
			$first = FreshRSS_http_Util::setRetryAfter($url, '', 'invalid');
			self::assertGreaterThanOrEqual($before + 2, $first);
			self::assertLessThanOrEqual(time() + 2, $first);
			$second = FreshRSS_http_Util::setRetryAfter($url, '', str_repeat('9', 100));
			self::assertLessThanOrEqual(time() + 5, $second);
			self::assertSame($second, FreshRSS_http_Util::setRetryAfter($url, '', '0'));
			self::assertSame($second, FreshRSS_http_Util::getRetryAfter($url, ''));
			self::assertSame(0, FreshRSS_http_Util::cleanRetryAfters(0));
		} finally {
			@unlink($file);
			@unlink(dirname($file) . '/0/' . basename($file));
			@unlink(dirname($file) . '/1/' . basename($file));
		}
	}

	public function testRssAndWebRequestsShareOptionPrecedenceAndCacheIdentity(): void {
		$this->startServer();
		FreshRSS_Context::systemConf()->__set('curl_options', [CURLOPT_HTTPHEADER => ['X-Test: system']]);
		$attributes = ['curl_params' => [CURLOPT_HTTPHEADER => ['X-Test: feed']]];
		$options = FreshRSS_http_Util::mergeCurlOptions($attributes);
		$feed = new FreshRSS_SimplePieCustom($attributes);
		$equivalent = new FreshRSS_SimplePieCustom([], $options);
		self::assertSame($feed->get_cache_filename($this->url), $equivalent->get_cache_filename($this->url));
		$other = new FreshRSS_SimplePieCustom(['curl_params' => [CURLOPT_HTTPHEADER => ['X-Test: changed']]]);
		self::assertNotSame($feed->get_cache_filename($this->url), $other->get_cache_filename($this->url));
		$web = FreshRSS_http_Util::httpGet($this->url . '/headers', type: 'json', attributes: $attributes);
		$rss = new FreshRSS_SimplePieFetch($this->url . '/headers', curl_options: $options);
		self::assertFalse($web['fail']);
		self::assertTrue($rss->success);
		$webHeaders = json_decode($web['body'], true, flags: JSON_THROW_ON_ERROR);
		$rssHeaders = json_decode($rss->get_body_content(), true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($webHeaders);
		self::assertIsArray($rssHeaders);
		self::assertSame('feed', $webHeaders['X-Test']);
		self::assertSame('feed', $rssHeaders['X-Test']);
		$override = FreshRSS_http_Util::mergeCurlOptions($attributes, [CURLOPT_HTTPHEADER => ['X-Test: explicit']]);
		self::assertSame(['X-Test: explicit'], $override[CURLOPT_HTTPHEADER]);
	}

	public function testFeedTimeoutOverridesSystemMillisecondDefaults(): void {
		$this->startServer();
		FreshRSS_Context::systemConf()->__set('curl_options', [CURLOPT_TIMEOUT_MS => 10, CURLOPT_CONNECTTIMEOUT_MS => 10]);
		$attributes = ['timeout' => 2];
		$result = FreshRSS_http_Util::httpGet($this->url . '/slow', type: 'json', attributes: $attributes);
		self::assertFalse($result['fail'], $result['error']);
		$options = FreshRSS_http_Util::mergeCurlOptions($attributes);
		$fetch = new FreshRSS_SimplePieFetch($this->url . '/slow', curl_options: $options);
		self::assertTrue($fetch->success, $fetch->error ?? '');
		self::assertSame($result['body'], $fetch->get_body_content());
	}

	public function testSimplePieStripsInitialAuthenticationHeadersAcrossOrigins(): void {
		$this->startServer();
		$port = parse_url($this->url, PHP_URL_PORT);
		FreshRSS_Context::systemConf()->internal_host_allowlist = ['127.0.0.1:' . $port, 'localhost:' . $port];
		$fetch = new FreshRSS_SimplePieFetch($this->url . '/redirect', headers: [
			'Authorization' => 'Bearer secret', 'Cookie' => 'private=secret', 'X-Test' => 'retained',
		]);
		self::assertTrue($fetch->success, $fetch->error ?? '');
		$headers = json_decode($fetch->get_body_content(), true, flags: JSON_THROW_ON_ERROR);
		self::assertIsArray($headers);
		self::assertArrayNotHasKey('Authorization', $headers);
		self::assertArrayNotHasKey('Cookie', $headers);
		self::assertSame('retained', $headers['X-Test']);
	}

	public function testSimplePieRejectsUnsafeTransportAndRespectsRetryAfterBeforeConnecting(): void {
		$this->startServer();
		$fetch = new FreshRSS_SimplePieFetch('file:///etc/hosts');
		self::assertFalse($fetch->success);
		self::assertSame('', $fetch->get_body_content());
		self::assertSame('Fetching non-remote URLs is not permitted', $fetch->error);
		$fetch = new FreshRSS_SimplePieFetch($this->url . '/slow', force_fsockopen: true);
		self::assertFalse($fetch->success);
		self::assertSame('', file_get_contents($this->counter));
		$url = $this->url . '/slow';
		$method = new ReflectionMethod(FreshRSS_http_Util::class, 'getRetryAfterFile');
		$file = $method->invoke(null, $url, '');
		self::assertIsString($file);
		try {
			FreshRSS_http_Util::setRetryAfter($url, '', '60');
			$fetch = new FreshRSS_SimplePieFetch($url);
			self::assertFalse($fetch->success);
			self::assertSame(429, $fetch->get_status_code());
			self::assertSame('', file_get_contents($this->counter));
		} finally {
			@unlink($file);
			@unlink(dirname($file) . '/0/' . basename($file));
			@unlink(dirname($file) . '/1/' . basename($file));
		}
	}

	public function testSimplePieHonoursServerRetryAfterOnHttpErrors(): void {
		$this->startServer();
		$limits = FreshRSS_Context::systemConf()->limits;
		$limits['retry_after_default'] = 120;
		$limits['retry_after_max'] = 180;
		FreshRSS_Context::systemConf()->limits = $limits;
		$url = $this->url . '/throttled';
		$method = new ReflectionMethod(FreshRSS_http_Util::class, 'getRetryAfterFile');
		$file = $method->invoke(null, $url, '');
		self::assertIsString($file);
		try {
			$before = time();
			$fetch = new FreshRSS_SimplePieFetch($url);
			self::assertFalse($fetch->success);
			self::assertSame(429, $fetch->get_status_code());
			self::assertSame('', $fetch->get_body_content());
			$retry = FreshRSS_http_Util::getRetryAfter($url, '');
			self::assertGreaterThanOrEqual($before + 3, $retry);
			self::assertLessThanOrEqual(time() + 3, $retry);
		} finally {
			@unlink($file);
			@unlink(dirname($file) . '/0/' . basename($file));
			@unlink(dirname($file) . '/1/' . basename($file));
		}
	}

	public function testDecodedGzipAndChunkedResponsesAreBoundedInBothTransports(): void {
		$curl = curl_version();
		self::assertIsArray($curl);
		self::assertIsString($curl['version']);
		if (version_compare($curl['version'], '8.20.0', '<')) {
			self::markTestSkipped('Native decoded-byte enforcement is verified with libcurl 8.20.0 and later');
		}
		$this->startServer();
		$limits = FreshRSS_Context::systemConf()->limits;
		$limits['http_max_response_bytes'] = 128;
		FreshRSS_Context::systemConf()->limits = $limits;
		foreach (['/large', '/gzip-chunked'] as $path) {
			$fetch = new FreshRSS_SimplePieFetch($this->url . $path);
			self::assertFalse($fetch->success);
			self::assertNotNull($fetch->error);
			self::assertStringContainsString('cURL error 63', $fetch->error);
			self::assertSame('', $fetch->get_body_content());
			$result = FreshRSS_http_Util::httpGet($this->url . $path, type: 'json');
			self::assertTrue($result['fail']);
			self::assertStringContainsString('http_max_response_bytes', $result['error']);
			self::assertSame('', $result['body']);
		}
	}

	public function testUserHeadersCannotInjectAdditionalLines(): void {
		$options = FreshRSS_http_Util::sanitizeCurlParams([CURLOPT_HTTPHEADER => ["X-Test: value\r\nRemote-User: admin", 'Accept: application/json']]);
		self::assertIsArray($options[CURLOPT_HTTPHEADER]);
		self::assertSame(['Accept: application/json'], array_values($options[CURLOPT_HTTPHEADER]));
	}
}
