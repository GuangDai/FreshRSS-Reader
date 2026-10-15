<?php
declare(strict_types=1);

final class FreshRSS_http_Util {

	private const RETRY_AFTER_PATH = DATA_PATH . '/Retry-After/';
	private const PRIVATE_SUBNETS = [
		'127.0.0.0/8',    // RFC1700 (Loopback)
		'10.0.0.0/8',     // RFC1918
		'192.168.0.0/16', // RFC1918
		'172.16.0.0/12',  // RFC1918
		'169.254.0.0/16', // RFC3927
		'0.0.0.0/8',      // RFC5735
		'240.0.0.0/4',    // RFC1112
		'100.64.0.0/10',  // RFC6598 (Shared Address Space of Carrier-Grade NAT)
		'::1/128',        // Loopback
		'fc00::/7',       // Unique Local Address
		'fe80::/10',      // Link Local Address
		'::ffff:0:0/96',  // IPv4 translations
		'64:ff9b::/96',   // RFC6052 (IPv6 Addressing of IPv4/IPv6 Translators, NAT64)
		'64:ff9b:1::/48', // RFC8215 (Local-Use IPv4/IPv6 Translation Prefix)
		'::/128',         // Unspecified address
	];
	/** @var array<string, string[]> $resolve_ok */
	private static array $resolve_ok = [];
	/** @var array<string, bool> $retry_after_domain_wide */
	private static array $retry_after_domain_wide = [];

	private static function getRetryAfterFile(string $url, string $proxy): string {
		$domain = parse_url($url, PHP_URL_HOST);
		if (!is_string($domain) || $domain === '') {
			return '';
		}
		$domain = strtolower($domain);
		// Once per host, as serverIsPublic() may resolve it
		$domainWide = self::$retry_after_domain_wide[$domain] ??= Minz_Request::serverIsPublic($url);
		$port = parse_url($url, PHP_URL_PORT);
		if (is_int($port)) {
			$domain .= ':' . $port;
		}
		return self::RETRY_AFTER_PATH . urlencode($domain) .
			($domainWide ? '' : '_' . hash('sha256', $url)) .
			(empty($proxy) ? '' : '_' . urlencode($proxy)) . '.txt';
	}

	/** @return list<string> Legacy storage is included until its records have been migrated. */
	private static function retryAfterFiles(string $file): array {
		$name = basename($file);
		return [$file, self::RETRY_AFTER_PATH . '0/' . $name, self::RETRY_AFTER_PATH . '1/' . $name];
	}

	/**
	 * Remove expired Retry-After files without collecting the directory in memory.
	 * Surviving records move to the other sweep directory, so successive bounded scans make progress.
	 * A zero scan limit disables cleanup. Returns the number of removed files.
	 */
	public static function cleanRetryAfters(?int $limit = null): int {
		$limit ??= FreshRSS_Context::systemConf()->limits['retry_after_cleanup_limit'] ?? 1000;
		if ($limit <= 0 || !is_dir(self::RETRY_AFTER_PATH)) {
			return 0;
		}
		$lock = @fopen(self::RETRY_AFTER_PATH . '.lock', 'c+');
		if ($lock === false) {
			return 0;
		}
		try {
			if (!flock($lock, LOCK_EX | LOCK_NB)) {
				return 0;
			}
			$phase = fread($lock, 1);
			$phase = in_array($phase, ['0', '1'], true) ? $phase : '';
			$from = self::RETRY_AFTER_PATH . ($phase === '' ? '' : $phase . '/');
			$next = $phase === '0' ? '1' : '0';
			$to = self::RETRY_AFTER_PATH . $next . '/';
			if ((!is_dir($from) && !@mkdir($from)) || (!is_dir($to) && !@mkdir($to))) {
				return 0;
			}
			$directory = @opendir($from);
			if ($directory === false) {
				return 0;
			}
			$removed = 0;
			$now = time();
			try {
				while ($limit > 0) {
					$name = readdir($directory);
					if ($name === false) {
						// Persist only at EOF; no skipped prefix needs to be rescanned next time.
						rewind($lock);
						fwrite($lock, $next);
						ftruncate($lock, 1);
						break;
					}
					if ($name === '.' || $name === '..' || $name === '.lock' || $name === '0' || $name === '1') {
						continue;
					}
					$file = $from . $name;
					clearstatcache(true, $file);
					if (!str_ends_with($name, '.txt') || is_link($file) || !is_file($file)) {
						continue;
					}
					$limit--;
					$mtime = @filemtime($file);
					if ($mtime !== false && $mtime <= $now) {
						if (@unlink($file)) {
							$removed++;
						}
					} elseif ($mtime !== false) {
						$destination = $to . $name;
						clearstatcache(true, $destination);
						$otherExpiry = @filemtime($destination);
						if ($otherExpiry !== false && $otherExpiry > $mtime) {
							@unlink($file);
						} else {
							@rename($file, $destination);
						}
					}
				}
			} finally {
				closedir($directory);
			}
			return $removed;
		} finally {
			fclose($lock);
		}
	}

	/**
	 * Check whether the URL needs to wait for a Retry-After period.
	 * @return int The timestamp of when the Retry-After expires, or 0 if not set.
	 */
	public static function getRetryAfter(string $url, string $proxy): int {
		$txt = self::getRetryAfterFile($url, $proxy);
		if ($txt === '' || !is_dir(self::RETRY_AFTER_PATH)) {
			return 0;
		}
		$lock = @fopen(self::RETRY_AFTER_PATH . '.lock', 'c');
		if ($lock === false) {
			return 0;
		}
		try {
			if (!flock($lock, LOCK_SH)) {
				return 0;
			}
			$retryAfter = 0;
			foreach (self::retryAfterFiles($txt) as $file) {
				clearstatcache(true, $file);
				$retryAfter = max($retryAfter, @filemtime($file) ?: 0);
			}
			return $retryAfter > time() ? $retryAfter : 0;
		} finally {
			fclose($lock);
		}
	}

	/**
	 * Store Retry-After seconds or an HTTP date. Concurrent responses cannot shorten an active wait.
	 * Configured default and maximum are seconds; zero disables the fallback or the maximum respectively.
	 */
	public static function setRetryAfter(string $url, string $proxy, string $retryAfter): int {
		$txt = self::getRetryAfterFile($url, $proxy);
		if ($txt === '') {
			return 0;
		}
		$limits = FreshRSS_Context::systemConf()->limits;
		$now = time();
		$default = max(0, $limits['retry_after_default'] ?? 1500);
		$maximum = max(0, $limits['retry_after_max'] ?? 172800);
		$retryAfter = trim($retryAfter);
		if (ctype_digit($retryAfter)) {
			// Clamp before addition, including numeric headers larger than PHP_INT_MAX.
			$delay = min((int)$retryAfter, PHP_INT_MAX - $now);
			$timestamp = $now + ($maximum > 0 ? min($delay, $maximum) : $delay);
		} else {
			$timestamp = \SimplePie\Misc::parse_date($retryAfter) ?: ($now + min($default, PHP_INT_MAX - $now));
			if ($maximum > 0) {
				$timestamp = min($timestamp, $now + min($maximum, PHP_INT_MAX - $now));
			}
		}
		@mkdir(self::RETRY_AFTER_PATH);
		$lock = @fopen(self::RETRY_AFTER_PATH . '.lock', 'c+');
		if ($lock === false) {
			return 0;
		}
		try {
			if (!flock($lock, LOCK_EX)) {
				return 0;
			}
			$files = self::retryAfterFiles($txt);
			foreach ($files as $file) {
				clearstatcache(true, $file);
				$timestamp = max($timestamp, @filemtime($file) ?: 0);
			}
			if ($timestamp <= $now) {
				return 0;
			}
			$phase = fread($lock, 1) === '0' ? '1' : '0'; // New writes join the next sweep, never its unchecked prefix.
			$directory = self::RETRY_AFTER_PATH . $phase . '/';
			$destination = $directory . basename($txt);
			if ((!is_dir($directory) && !@mkdir($directory)) || !@touch($destination, $timestamp)) {
				Minz_Log::error('Failed to set Retry-After for ' . \SimplePie\Misc::url_remove_credentials($url));
				return 0;
			}
			foreach ($files as $file) {
				if ($file !== $destination) {
					@unlink($file);
				}
			}
			return $timestamp;
		} finally {
			fclose($lock);
		}
	}

	/**
	 * @param array<mixed> $curl_params
	 * @return array<mixed>
	 */
	public static function sanitizeCurlParams(array $curl_params): array {
		$safe_params = [
			CURLOPT_COOKIE,
			CURLOPT_COOKIEFILE,
			CURLOPT_FOLLOWLOCATION,	// We filter this value later, only allowing `false`
			CURLOPT_HTTPHEADER,
			CURLOPT_MAXREDIRS,
			CURLOPT_POST,
			CURLOPT_POSTFIELDS,
			CURLOPT_PROXY,
			CURLOPT_PROXYTYPE,
			CURLOPT_USERAGENT,
		];
		foreach ($curl_params as $k => $_) {
			if (!in_array($k, $safe_params, true)) {
				unset($curl_params[$k]);
				continue;
			}
			// Allow only an empty value just to enable the libcurl cookie engine
			if ($k === CURLOPT_COOKIEFILE) {
				$curl_params[$k] = '';
			}
			// Remove HTTP authentication headers problematic for security
			if ($k === CURLOPT_HTTPHEADER && is_array($curl_params[$k])) {
				$curl_params[$k] = array_filter($curl_params[$k],
					fn($header) => is_string($header) && !str_contains($header, "\r") && !str_contains($header, "\n") &&
						!preg_match('/^(Remote[-_\s]*User|X[-_\s]*WebAuth[-_\s]*User)\\s*:/i', $header));
			}
		}
		return $curl_params;
	}

	/**
	 * Shared precedence for RSS cache identity and all HTTP requests: defaults, feed settings, explicit overrides.
	 * @param array<string,mixed> $attributes
	 * @param array<int,mixed> $overrides
	 * @return array<int,mixed>
	 */
	public static function mergeCurlOptions(array $attributes = [], array $overrides = []): array {
		$options = FreshRSS_Context::systemConf()->curl_options;
		$timeout = $attributes['timeout'] ?? null;
		if (is_numeric($timeout) && ($seconds = (int)$timeout) > 0) {
			unset($options[CURLOPT_TIMEOUT_MS], $options[CURLOPT_CONNECTTIMEOUT_MS]);
			$options[CURLOPT_TIMEOUT] = $seconds;
			$options[CURLOPT_CONNECTTIMEOUT] = $seconds;
		}
		if (isset($attributes['ssl_verify'])) {
			$options[CURLOPT_SSL_VERIFYHOST] = empty($attributes['ssl_verify']) ? 0 : 2;
			$options[CURLOPT_SSL_VERIFYPEER] = (bool)$attributes['ssl_verify'];
			if (empty($attributes['ssl_verify'])) {
				$options[CURLOPT_SSL_CIPHER_LIST] = 'DEFAULT@SECLEVEL=1';
			}
		}
		foreach (self::sanitizeCurlParams(is_array($attributes['curl_params'] ?? null) ? $attributes['curl_params'] : []) as $key => $value) {
			if (is_int($key)) {
				$options[$key] = $value;
			}
		}
		$options = array_replace($options, $overrides);
		$proxyType = $options[CURLOPT_PROXYTYPE] ?? null;
		if (is_int($proxyType) && ($proxyType < 0 || $proxyType === 3)) {
			// Negative values and the legacy value 3 explicitly disable the proxy, including environment defaults.
			unset($options[CURLOPT_PROXYTYPE]);
			$options[CURLOPT_PROXY] = '';
		}
		return $options;
	}

	private static function idn_to_puny(string $url): string {
		if (function_exists('idn_to_ascii')) {
			$idn = parse_url($url, PHP_URL_HOST);
			if (is_string($idn) && $idn != '') {
				$puny = idn_to_ascii($idn);
				$pos = strpos($url, $idn);
				if ($puny != false && $pos !== false) {
					$url = substr_replace($url, $puny, $pos, strlen($idn));
				}
			}
		}
		return $url;
	}

	public static function checkUrl(string $url, bool $fixScheme = true): string|false {
		$url = trim($url);
		if ($url == '') {
			return '';
		}
		if ($fixScheme && preg_match('#^https?://#i', $url) !== 1) {
			$url = 'https://' . ltrim($url, '/');
		}

		$url = self::idn_to_puny($url);	// https://bugs.php.net/bug.php?id=53474
		$urlRelaxed = str_replace('_', 'z', $url);	//PHP discussion #64948 Underscore

		if (is_string(filter_var($urlRelaxed, FILTER_VALIDATE_URL))) {
			return $url;
		} else {
			return false;
		}
	}

	/**
	 * Remove the charset meta information of an HTML document, e.g.:
	 * `<meta charset="..." />`
	 * `<meta http-equiv="Content-Type" content="text/html; charset=...">`
	 */
	private static function stripHtmlMetaCharset(string $html): string {
		return preg_replace('/<meta\s[^>]*charset\s*=\s*[^>]+>/i', '', $html, 1) ?? '';
	}

	/**
	 * Set an XML preamble to enforce the HTML content type charset received by HTTP.
	 * @param string $html the raw downloaded HTML content
	 * @param string $contentType an HTTP Content-Type such as 'text/html; charset=utf-8'
	 * @return string an HTML string with XML encoding information for DOMDocument::loadHTML()
	 */
	private static function enforceHttpEncoding(string $html, string $contentType = ''): string {
		$httpCharset = preg_match('/\bcharset=([0-9a-z_-]{2,12})$/i', $contentType, $matches) === 1 ? $matches[1] : '';
		if ($httpCharset == '') {
			// No charset defined by HTTP
			if (preg_match('/<meta\s[^>]*charset\s*=[\s\'"]*UTF-?8\b/i', substr($html, 0, 2048))) {
				// Detect UTF-8 even if declared too deep in HTML for DOMDocument
				$httpCharset = 'UTF-8';
			} else {
				// Do nothing
				return $html;
			}
		}
		$httpCharsetNormalized = \SimplePie\Misc::encoding($httpCharset);
		if (in_array($httpCharsetNormalized, ['windows-1252', 'US-ASCII'], true)) {
			// Default charset for HTTP, do nothing
			return $html;
		}
		if (substr($html, 0, 3) === "\xEF\xBB\xBF" || // UTF-8 BOM
			substr($html, 0, 2) === "\xFF\xFE" || // UTF-16 Little Endian BOM
			substr($html, 0, 2) === "\xFE\xFF" || // UTF-16 Big Endian BOM
			substr($html, 0, 4) === "\xFF\xFE\x00\x00" || // UTF-32 Little Endian BOM
			substr($html, 0, 4) === "\x00\x00\xFE\xFF") { // UTF-32 Big Endian BOM
			// Existing byte order mark, do nothing
			return $html;
		}
		if (preg_match('/^<[?]xml[^>]+encoding\b/', substr($html, 0, 64))) {
			// Existing XML declaration, do nothing
			return $html;
		}
		if ($httpCharsetNormalized !== 'UTF-8') {
			// Try to change encoding to UTF-8 using mbstring or iconv or intl
			$utf8 = \SimplePie\Misc::change_encoding($html, $httpCharsetNormalized, 'UTF-8');
			if (is_string($utf8)) {
				$html = self::stripHtmlMetaCharset($utf8);
				$httpCharsetNormalized = 'UTF-8';
			}
		}
		if ($httpCharsetNormalized === 'UTF-8') {
			// Save encoding information as Unicode BOM
			return "\xEF\xBB\xBF" . $html;
		}
		// Give up
		return $html;
	}

	/**
	 * Set an HTML base URL to the HTML content if there is none.
	 * @param string $html the raw downloaded HTML content
	 * @param string $href the HTML base URL
	 * @return string an HTML string
	 */
	private static function enforceHtmlBase(string $html, string $href): string {
		$doc = new DOMDocument();
		$doc->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		if ($doc->documentElement === null) {
			return '';
		}
		$xpath = new DOMXPath($doc);
		$bases = $xpath->evaluate('//base');
		if (!($bases instanceof DOMNodeList) || $bases->length === 0) {
			$base = $doc->createElement('base');
			if ($base === false) {
				return $html;
			}
			$base->setAttribute('href', $href);
			$head = null;
			$heads = $xpath->evaluate('//head');
			if ($heads instanceof DOMNodeList && $heads->length > 0) {
				$head = $heads->item(0);
			}
			if ($head instanceof DOMElement) {
				$head->insertBefore($base, $head->firstChild);
			} else {
				$doc->documentElement->insertBefore($base, $doc->documentElement->firstChild);
			}
		}

		// Save the start of HTML because libxml2 saveHTML() risks scrambling it
		$htmlPos = stripos($html, '<html');
		$htmlStart = $htmlPos === false || $htmlPos > 512 ? '' : substr($html, 0, $htmlPos);

		$html = $doc->saveHTML() ?: $html;
		if ($htmlStart !== '' && !str_starts_with($html, $htmlStart)) {
			// libxml2 saveHTML() risks removing Unicode BOM and XML declaration,
			// which affects future detection of charset encoding, so manually restore it
			$htmlPos = stripos($html, '<html');
			$html = $htmlPos === false || $htmlPos > 512 ? $html : $htmlStart . substr($html, $htmlPos);
		}
		return $html;
	}

	public static function compareURLOrigins(string $url1, string $url2): bool {
		$url1 = parse_url(strtolower($url1));
		$url2 = parse_url(strtolower($url2));
		if ($url1 === false || $url2 === false) {
			return false;
		}
		foreach ([&$url1, &$url2] as &$url) {
			$url['port'] ??= match ($url['scheme'] ?? '') {
				'http' => 80,
				'https' => 443,
				default => 0,
			};
		}
		return ($url1['scheme'] ?? '') === ($url2['scheme'] ?? '') &&
			($url1['host'] ?? '') === ($url2['host'] ?? '') &&
			($url1['port'] ?? '') === ($url2['port'] ?? '');
	}

	/**
	 * Return 0 if values on either side are equal ignoring the HTTP vs HTTPS differences, or 1/-1 if they differ.
	 */
	public static function compareUrlIgnoringHttps(string $url1, string $url2): int {
		$normalizeScheme = static fn(string $url): string => preg_replace('#^https?://#i', '//', trim($url)) ?? $url;
		return $normalizeScheme($url1) <=> $normalizeScheme($url2);
	}

	/**
	 * Returns a value for CURLOPT_RESOLVE as an array, null if no allowed IPs were found, false if the domain failed to resolve.
	 *
	 * @return array<string>|null|false
	 */
	public static function getCurlResolveInfo(string $url): array|null|false {
		$url = strtolower($url);
		$parsed = parse_url($url);
		if ($parsed === false) {
			return false;
		}
		$host = $parsed['host'] ?? null;
		$scheme = $parsed['scheme'] ?? null;
		if ($host === null || $scheme === null) {
			return false;
		}
		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			if (strlen($host) === 2) {
				return false;
			}
			$host = substr($host, 1, strlen($host) - 2);
		}

		$internal_host_allowlist = getenv('INTERNAL_HOST_ALLOWLIST');
		if (is_string($internal_host_allowlist) && $internal_host_allowlist !== '') {
			$internal_host_allowlist = preg_split('/\s+/', $internal_host_allowlist, -1, PREG_SPLIT_NO_EMPTY);
		}
		if (!is_array($internal_host_allowlist) || empty($internal_host_allowlist)) {
			$internal_host_allowlist = FreshRSS_Context::systemConf()->internal_host_allowlist;
		}

		$port = parse_url($url)['port'] ?? match ($scheme) {
			'http' => 80,
			'https' => 443,
			'socks4' => 1080,
			'socks4a' => 1080,
			'socks5' => 1080,
			'socks5h' => 1080,
			default => 0,
		};
		if (in_array('*', $internal_host_allowlist, true)) {
			return [];	// Disables SSRF checks entirely (unsafe)
		}

		$resolve_str = "$host:$port:";
		$ips_ok = [];
		$ips = [];
		$records = [];
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			$ips[] = $host;
		} elseif (isset(self::$resolve_ok[$host])) {
			$ips = self::$resolve_ok[$host];
		} else {
			$records = @dns_get_record($host, DNS_A + DNS_AAAA);
			if ($records === false) {
				return false;
			}
			foreach ($records as $record) {
				$ip = $record['ip'] ?? $record['ipv6'];
				if (is_string($ip)) {
					$ips[] = $ip;
				}
			}
			self::$resolve_ok[$host] = $ips;
		}

		$cidr_allowlist = array_filter($internal_host_allowlist, fn($v, $_) => str_contains($v, '/'), ARRAY_FILTER_USE_BOTH);
		foreach ($ips as $ip) {
			$allowlist_str = "$ip:$port";
			$add_ip = $ip;
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
				$allowlist_str = "[$ip]:$port";
				$add_ip = "[$ip]";
			}
			foreach ($cidr_allowlist as $cidr) {
				if (self::checkCIDR($ip, $cidr)) {
					$ips_ok[] = $add_ip;
					continue 2;
				}
			}
			if (in_array($allowlist_str, $internal_host_allowlist, true) ||
				in_array("$host:$port", $internal_host_allowlist, true)) {
				$ips_ok[] = $add_ip;
				continue;
			}

			// NAT64 addresses use the RFC 6052 well-known prefix.
			// Validate the embedded IPv4 address because the NAT64 IPv6 address itself
			// is globally routable even when it maps to a private/reserved IPv4 address.
			if (self::checkCIDR($ip, '64:ff9b::/96')) {
				$packed = @inet_pton($ip);
				if ($packed === false || strlen($packed) !== 16) {
					continue;
				}

				$embedded_ipv4 = @inet_ntop(substr($packed, 12, 4));
				if ($embedded_ipv4 === false) {
					continue;
				}

				if (filter_var($embedded_ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
					continue;
				}

				// Extra check because the above one might not be enough: https://github.com/php/php-src/issues/16944
				foreach (self::PRIVATE_SUBNETS as $cidr) {
					if (self::checkCIDR($embedded_ipv4, $cidr)) {
						continue 2;
					}
				}

				$ips_ok[] = $add_ip;
				continue;
			}

			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
				continue;
			}
			// Extra check because the above one might not be enough: https://github.com/php/php-src/issues/16944
			// Workaround is available by using `FILTER_FLAG_GLOBAL_RANGE` instead, but that was only added in PHP 8.2, and we need to support PHP 8.1+
			foreach (self::PRIVATE_SUBNETS as $cidr) {
				if (self::checkCIDR($ip, $cidr)) {
					continue 2;
				}
			}

			$ips_ok[] = $add_ip;
		}

		if (count($ips_ok) > 0) {
			if (count($records) > 0 || isset(self::$resolve_ok[$host])) {
				$resolve_str .= implode(',', $ips_ok);
				return [$resolve_str];
			}
			if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
				// No resolve overrides since the URL only contained an IP, not a domain
				return [];
			}
		}

		if (count($ips) === 0) {
			return false;
		}

		return null;
	}


	/**
	 * @param non-empty-string $url
	 * @param string|null $cachePath path to cache file, or `null` to disable caching
	 * @param string $type {html,ico,json,opml,xml}
	 * @param array<string,mixed> $attributes May contain user-defined cURL options in `$attributes['curl_params']`
	 * @param array<int,mixed> $curl_options Internal overrides of cURL options
	 * @return array{body:string,effective_url:string,redirect_count:int,fail:bool,status:int,error:string}
	 *   `status` is the HTTP response code (e.g. 200, 404), or a custom negative value:
	 *   * `-200` served from local cache;
	 *   * `-429` blocked by active `Retry-After` period;
	 *   * `-500` `curl_init()` failure.
	 */
	public static function httpGet(string $url, ?string $cachePath = null, string $type = 'html', array $attributes = [], array $curl_options = []): array {
		if (!\SimplePie\Misc::is_remote_uri($url)) {
			return ['body' => '', 'effective_url' => '', 'redirect_count' => 0, 'fail' => true, 'status' => -500, 'error' => 'Malformed HTTP URL'];
		}
		$limits = FreshRSS_Context::systemConf()->limits;
		$maxBytes = max(0, $limits['http_max_response_bytes'] ?? 16777216);
		$duration = max(0, intval($limits['cache_duration']));
		$cached = $cachePath === null ? null : self::readHttpCache($cachePath, $maxBytes, time() - $duration);
		if ($cached !== null) {
			return ['body' => $cached, 'effective_url' => $url, 'redirect_count' => 0, 'fail' => false, 'status' => -200, 'error' => ''];
		}
		// A stable, hidden lock file survives atomic replacement and cache cleanup.
		$lock = $cachePath === null ? false : @fopen(dirname($cachePath) . '/.' . basename($cachePath) . '.lock', 'c');
		try {
			$locked = $lock !== false && flock($lock, LOCK_EX);
			if ($locked && $cachePath !== null) {
				// Another worker may have refreshed the same cache while we waited.
				$cached = self::readHttpCache($cachePath, $maxBytes, time() - $duration);
				if ($cached !== null) {
					return ['body' => $cached, 'effective_url' => $url, 'redirect_count' => 0, 'fail' => false, 'status' => -200, 'error' => ''];
				}
			}
			$previous = $cachePath === null ? null : self::readHttpCache($cachePath, $maxBytes);
			$result = self::httpGetUncached($url, $type, $attributes, $curl_options);
			if ($result['status'] === 304 && $result['error'] === '') {
				if ($previous === null) {
					$result['fail'] = true;
					$result['error'] = 'HTTP 304 received without a usable cached response';
				} else {
					$result['body'] = $previous;
					$result['fail'] = false;
				}
			}
			if (!$result['fail'] && $cachePath !== null && (!$locked || !self::writeHttpCache($cachePath, $result['body']))) {
				Minz_Log::warning('Error saving HTTP cache for ' . \SimplePie\Misc::url_remove_credentials($url));
			}
			return $result;
		} finally {
			if ($lock !== false) {
				fclose($lock);
			}
		}
	}

	/**
	 * Remove an expired cache only while its stable publication lock is idle.
	 * Cooperating httpGet() writers cannot replace the pathname between the age check and unlink.
	 */
	public static function removeExpiredCacheFile(string $path, int $cutoff): bool {
		if (str_starts_with(basename($path), '.')) {
			return false;
		}
		$lock = @fopen(dirname($path) . '/.' . basename($path) . '.lock', 'c');
		if ($lock === false) {
			return false;
		}
		try {
			if (!flock($lock, LOCK_EX | LOCK_NB)) {
				return false;
			}
			clearstatcache(true, $path);
			if (is_link($path) || !is_file($path)) {
				return false;
			}
			$mtime = @filemtime($path);
			return $mtime !== false && $mtime < $cutoff && @unlink($path);
		} finally {
			fclose($lock);
		}
	}

	/** Read size and contents from the same inode, including during concurrent cache replacement. */
	private static function readHttpCache(string $path, int $maxBytes, int $newerThan = 0): ?string {
		$file = @fopen($path, 'rb');
		if ($file === false) {
			return null;
		}
		try {
			if (!flock($file, LOCK_SH)) {
				return null;
			}
			$stat = fstat($file);
			if ($stat === false || $stat['mtime'] <= $newerThan || $stat['size'] === 0 || ($maxBytes > 0 && $stat['size'] > $maxBytes)) {
				return null;
			}
			$body = stream_get_contents($file, $maxBytes > 0 ? $maxBytes : null);
			return is_string($body) && strlen($body) === $stat['size'] ? $body : null;
		} finally {
			fclose($file);
		}
	}

	/** Publish a complete response with one rename; readers see either complete version. */
	private static function writeHttpCache(string $path, string $body): bool {
		$temporary = @tempnam(dirname($path), '.http-');
		if ($temporary === false) {
			return false;
		}
		try {
			if (@file_put_contents($temporary, $body) !== strlen($body)) {
				return false;
			}
			$permissions = @fileperms($path);
			@chmod($temporary, ($permissions !== false ? $permissions : (0666 & ~umask())) & 0666);
			return @rename($temporary, $path);
		} finally {
			if (is_file($temporary)) {
				@unlink($temporary);
			}
			clearstatcache(true, $path);
		}
	}

	/**
	 * @param array<string,mixed> $attributes
	 * @param array<int,mixed> $curl_options
	 * @return array{body:string,effective_url:string,redirect_count:int,fail:bool,status:int,error:string}
	 */
	private static function httpGetUncached(string $url, string $type, array $attributes, array $curl_options): array {
		$limits = FreshRSS_Context::systemConf()->limits;
		$maxBodyBytes = max(0, $limits['http_max_response_bytes'] ?? 16777216);
		$maxHeaderBytes = max(0, $limits['http_max_header_bytes'] ?? 65536);
		$feed_timeout = empty($attributes['timeout']) || !is_numeric($attributes['timeout']) ? 0 : intval($attributes['timeout']);

		$accept = '';
		$curl_options = self::mergeCurlOptions($attributes, $curl_options);
		$proxy = is_string($curl_options[CURLOPT_PROXY] ?? null) ? $curl_options[CURLOPT_PROXY] : '';
		$proxy_type = is_int($curl_options[CURLOPT_PROXYTYPE] ?? null) ? $curl_options[CURLOPT_PROXYTYPE] : 0;

		if (($retryAfter = FreshRSS_http_Util::getRetryAfter($url, $proxy)) > 0) {
			Minz_Log::warning('For that domain, will first retry after ' . date('c', $retryAfter) . '. ' . \SimplePie\Misc::url_remove_credentials($url));
			return ['body' => '', 'effective_url' => $url, 'redirect_count' => 0, 'fail' => true, 'status' => -429,
				'error' => 'Retry-After is active until ' . date('c', $retryAfter)];
		}

		if (FreshRSS_Context::systemConf()->simplepie_syslog_enabled) {
			syslog(LOG_INFO, 'FreshRSS GET ' . $type . ' ' . \SimplePie\Misc::url_remove_credentials($url));
		}

		switch ($type) {
			case 'json':
				$accept = 'application/json,application/feed+json,application/javascript;q=0.9,text/javascript;q=0.8,*/*;q=0.7';
				break;
			case 'opml':
				$accept = 'text/x-opml,text/xml;q=0.9,application/xml;q=0.9,*/*;q=0.8';
				break;
			case 'xml':
				$accept = 'application/xml,application/xhtml+xml,text/xml;q=0.9,*/*;q=0.8';
				break;
			case 'ico':
				$accept = 'image/x-icon,image/vnd.microsoft.icon,image/ico,image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.1';
				break;
			case 'html':
			default:
				$accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
				break;
		}

		if (is_array($curl_options[CURLOPT_HTTPHEADER] ?? null) && preg_grep('/^Accept\\s*:/i', $curl_options[CURLOPT_HTTPHEADER]) === []) {
			$curl_options[CURLOPT_HTTPHEADER][] = 'Accept: ' . $accept;
		}

		$original_url = $url;
		$fail = false;
		$redirs = 0;
		$max_redirs = $curl_options[CURLOPT_MAXREDIRS] ?? null;
		if (!is_int($max_redirs)) {
			$max_redirs = 4;
		}
		if (isset($curl_options[CURLOPT_FOLLOWLOCATION]) && empty($curl_options[CURLOPT_FOLLOWLOCATION])) {
			$max_redirs = 0;
		}
		while (true) {
			if ($redirs > 0 && self::getRetryAfter($url, $proxy) > 0) {
				return ['body' => '', 'effective_url' => $url, 'redirect_count' => $redirs, 'fail' => true, 'status' => -429, 'error' => 'Retry-After is active'];
			}
			$url = is_string($url) ? $url : '';
			$resolve = [];
			if ($proxy === '') {
				$resolve = self::getCurlResolveInfo($url);
				if ($resolve === null) {
					Minz_Log::warning('Fetching this URL is not allowed, because the host’s IP is not in the allowlist [' .
						\SimplePie\Misc::url_remove_credentials($url) . ']');
					return ['body' => '', 'effective_url' => '', 'redirect_count' => 0, 'fail' => true, 'status' => -500, 'error' => 'Host IP is not allowed'];
				} elseif ($resolve === false) {
					return ['body' => '', 'effective_url' => '', 'redirect_count' => 0, 'fail' => true, 'status' => -500, 'error' => 'Host DNS resolution failed'];
				}
				if (!empty($resolve)) {
					$curl_options[CURLOPT_RESOLVE] = $resolve;	// Prevent DNS rebinding
				}
			} else {
				defined('CURLPROXY_HTTPS') or define('CURLPROXY_HTTPS', 2);	// Compatibility cURL 7.51
				$proxy_scheme = match ($proxy_type) {
					CURLPROXY_HTTP => 'http',
					CURLPROXY_HTTPS => 'https',
					CURLPROXY_SOCKS4 => 'socks4',
					CURLPROXY_SOCKS4A => 'socks4a',
					CURLPROXY_SOCKS5 => 'socks5',
					CURLPROXY_SOCKS5_HOSTNAME => 'socks5h',
					default => null,
				};
				if ($proxy_scheme === null) {
					// Unsupported proxy type
					return ['body' => '', 'effective_url' => '', 'redirect_count' => 0, 'fail' => true, 'status' => -500, 'error' => 'Unsupported proxy type'];
				}
				$proxy_url = "$proxy_scheme://$proxy"; // CURLOPT_PROXY ($proxy) is formatted as user:pass@hostname:port, with the part before @ being optional
				$resolve = self::getCurlResolveInfo($proxy_url);
				if ($resolve === null) {
					Minz_Log::warning('Failed to fetch this URL, because the proxy’s IP is not in the allowlist [' .
						\SimplePie\Misc::url_remove_credentials($url) . '] [' .
						\SimplePie\Misc::url_remove_credentials($proxy_url) . ']');
					return ['body' => '', 'effective_url' => '', 'redirect_count' => 0, 'fail' => true, 'status' => -500, 'error' => 'Proxy IP is not allowed'];
				} elseif ($resolve === false) {
					return ['body' => '', 'effective_url' => '', 'redirect_count' => 0, 'fail' => true, 'status' => -500, 'error' => 'Proxy DNS resolution failed'];
				}
				if (!empty($resolve)) {
					// Only for the proxy domain, socks4a and socks5h DNS queries will be passed through the proxy.
					// For the other proxy protocols, note that IPs for internal domains can be leaked,
					// since the domain is resolved outside of the proxy.
					$curl_options[CURLOPT_RESOLVE] = $resolve;
				}
			}
			// TODO: Implement HTTP 1.1 conditional GET If-Modified-Since
			$ch = curl_init();
			if ($ch === false || $url === '') {
				return ['body' => '', 'effective_url' => '', 'redirect_count' => 0, 'fail' => true, 'status' => -500, 'error' => 'Could not initialise cURL'];
			}
			curl_setopt_array($ch, [
				CURLOPT_URL => $url,
				CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
				CURLOPT_USERAGENT => FRESHRSS_USERAGENT,
				CURLOPT_CONNECTTIMEOUT => $feed_timeout > 0 ? $feed_timeout : $limits['timeout'],
				CURLOPT_TIMEOUT => $feed_timeout > 0 ? $feed_timeout : $limits['timeout'],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ACCEPT_ENCODING => '',	//Enable all encodings
				//CURLOPT_VERBOSE => 1,	// To debug sent HTTP headers
			]);

			if (defined('CURLOPT_PROTOCOLS_STR') && is_int(CURLOPT_PROTOCOLS_STR)) {
				$curl_options[CURLOPT_PROTOCOLS_STR] = 'http,https';
				if (defined('CURLOPT_REDIR_PROTOCOLS_STR') && is_int(CURLOPT_REDIR_PROTOCOLS_STR)) {
					$curl_options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
				}
			} elseif (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
				// Legacy PHP 8.2-
				if (defined('CURLOPT_PROTOCOLS')) {
					$curl_options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
				}
				if (defined('CURLOPT_REDIR_PROTOCOLS')) {
					$curl_options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
				}
			}

			curl_setopt_array($ch, $curl_options);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);	// We handle HTTP redirections manually for security

			$responseHeaders = '';
			$body = '';
			$headerBytes = 0;
			$limitError = '';
			curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function (\CurlHandle $ch, string $header) use (
				&$responseHeaders,
				&$headerBytes,
				&$limitError,
				$maxHeaderBytes
			): int {
				$length = strlen($header);
				$headerBytes += $length;
				if ($maxHeaderBytes > 0 && $headerBytes > $maxHeaderBytes) {
					$limitError = 'HTTP response headers exceed http_max_header_bytes';
					return 0;
				}
				if (str_starts_with($header, 'HTTP/')) {
					$responseHeaders = ''; // Discard interim responses such as 100 Continue.
				}
				if (trim($header) !== '') {
					$responseHeaders .= $header;
				}
				return $length;
			});
			curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function (\CurlHandle $ch, string $chunk) use (&$body, &$limitError, $maxBodyBytes): int {
				$length = strlen($chunk);
				if ($maxBodyBytes > 0 && $length > $maxBodyBytes - strlen($body)) {
					$limitError = 'HTTP response body exceeds http_max_response_bytes';
					return 0;
				}
				$body .= $chunk;
				return $length;
			});

			$transfer = curl_exec($ch);
			$c_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$c_content_type = '' . curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
			$c_effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
			$c_error = $limitError !== '' ? $limitError : curl_error($ch);

			$headers = [];
			if ($transfer !== false) {
				$responseHeaders .= "\r\n";
				$responseHeaders = \SimplePie\HTTP\Parser::prepareHeaders($responseHeaders);
				$parser = new \SimplePie\HTTP\Parser($responseHeaders);
				if ($parser->parse()) {
					$headers = $parser->headers;
				}
			}

			if ($transfer !== false && in_array($c_status, [301, 302, 303, 307, 308], true)) {
				// Handle the redirect by making another request
				$location = \SimplePie\Misc::absolutize_url($headers['location'] ?? $url, $url);
				if ($location === false || !\SimplePie\Misc::is_remote_uri($location)) {
					Minz_Log::warning('Invalid redirect location: malformed URL “' . ($headers['location'] ?? $url) . '“');
					$fail = true;
					$body = '';
					$c_error = 'Invalid redirect location';
					break;
				}
				if (!self::compareURLOrigins($url, $location)) {
					unset($curl_options[CURLOPT_COOKIE]);
					unset($curl_options[CURLOPT_USERPWD], $curl_options[CURLOPT_USERNAME], $curl_options[CURLOPT_PASSWORD]);

					if (is_array($curl_options[CURLOPT_HTTPHEADER] ?? null)) {
						$curl_options[CURLOPT_HTTPHEADER] = array_filter($curl_options[CURLOPT_HTTPHEADER], fn(mixed $header): bool =>
							is_string($header) && !preg_match('/^(Cookie|Authorization)\\s*:/i', $header));
					}
				}
				if ($max_redirs >= 0 && $redirs >= $max_redirs) {
					$fail = true;
					$body = '';
					$c_error = 'Too many redirects';
					Minz_Log::warning('Error fetching content: Too many redirects were hit [' . \SimplePie\Misc::url_remove_credentials($original_url) . ']');
					break;
				}
				if (isset($curl_options[CURLOPT_POST]) &&
					in_array($c_status, [301, 302, 303], true)) {	// Not for 307 and 308, which must not change the HTTP method
					unset($curl_options[CURLOPT_POST]);
					unset($curl_options[CURLOPT_POSTFIELDS]);

					if (is_array($curl_options[CURLOPT_HTTPHEADER] ?? null)) {
						$curl_options[CURLOPT_HTTPHEADER] = array_filter($curl_options[CURLOPT_HTTPHEADER], fn(mixed $header): bool =>
							is_string($header) && !str_starts_with(strtolower(trim($header)), 'content-type:'));
					}
				}
				$redirs++;
				$url = $location;
				continue;
			}

			$fail = !in_array($c_status, [200, 304], true) || $c_error !== '' || $transfer === false;
			if ($fail) {
				$body = '';
				if ($c_error === '') {
					$c_error = 'HTTP ' . $c_status;
				}
				Minz_Log::warning('Error fetching content: HTTP code ' . $c_status . ': ' . $c_error . ' ' . $url);
				if (in_array($c_status, [429, 503], true)) {
					$retryAfter = FreshRSS_http_Util::setRetryAfter($url, $proxy, $headers['retry-after'] ?? '');
					if ($c_status === 429) {
						$errorMessage = 'HTTP 429 Too Many Requests! [' . \SimplePie\Misc::url_remove_credentials($url) . ']';
					} elseif ($c_status === 503) {
						$errorMessage = 'HTTP 503 Service Unavailable! [' . \SimplePie\Misc::url_remove_credentials($url) . ']';
					}
					if ($retryAfter > 0) {
						$errorMessage .= ' We may retry after ' . date('c', $retryAfter);
					}
				}
			} elseif (strlen($body) === 0) { // TODO: Implement HTTP 410 Gone
				$body = '';
			} else {
				if (in_array($type, ['html', 'json', 'opml', 'xml'], true)) {
					$body = trim($body, " \n\r\t\v");	// Do not trim \x00 to avoid breaking a BOM
				}
				if (in_array($type, ['html', 'xml', 'opml'], true)) {
					$body = self::enforceHttpEncoding($body, $c_content_type);
				}
				if (in_array($type, ['html'], true)) {
					if (stripos($c_content_type, 'text/plain') !== false) {
						// Plain text to be displayed as preformatted text. Prefixed with UTF-8 BOM
						$body = "\xEF\xBB\xBF" . '<pre class="text-plain">' . htmlspecialchars($body, ENT_NOQUOTES, 'UTF-8') . '</pre>';
					} else {
						$body = self::enforceHtmlBase($body, $c_effective_url);
					}
				}
			}
			break;
		}

		return ['body' => $body, 'effective_url' => $c_effective_url, 'redirect_count' => $redirs,
			'fail' => $fail, 'status' => $c_status, 'error' => $c_error];
	}

	/**
	 * Converts an IP (v4 or v6) to a binary representation using inet_pton
	 *
	 * @param string $ip the IP to convert
	 * @return string a binary representation of the specified IP
	 */
	private static function ipToBits(string $ip): string {
		$binaryip = '';
		foreach (str_split(inet_pton($ip) ?: '') as $char) {
			$binaryip .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
		}
		return $binaryip;
	}

	/**
	 * Check if an ip belongs to the provided range (in CIDR format)
	 *
	 * @param string $ip the IP that we want to verify (ex: 192.168.16.1)
	 * @param string $range the range to check against (ex: 192.168.16.0/24)
	 * @return bool true if the IP is in the range, otherwise false
	 */
	private static function checkCIDR(string $ip, string $range): bool {
		$binary_ip = self::ipToBits($ip);
		if ($binary_ip === '') {
			return false;
		}
		$split = explode('/', $range);

		$subnet = $split[0] ?? '';
		if ($subnet == '') {
			return false;
		}
		$binary_subnet = self::ipToBits($subnet);
		if ($binary_subnet === '') {
			return false;
		}
		if (strlen($binary_ip) !== strlen($binary_subnet)) {
			return false;	// Do not mix IPv4 and IPv6
		}

		$max_mask_bits = str_contains($ip, ':') ? 128 : 32;
		// A range without a subnet is a single address, like Apache’s mod_remoteip accepts
		$mask_bits_str = $split[1] ?? (string)$max_mask_bits;
		if (!ctype_digit($mask_bits_str)) {
			return false;
		}
		$mask_bits = (int)$mask_bits_str;
		if ($mask_bits < 0 || $mask_bits > $max_mask_bits) {
			return false;	// Reject invalid mask bits lengths
		}
		if ($mask_bits === 0) {
			return true;
		}

		$ip_net_bits = substr($binary_ip, 0, $mask_bits);
		$subnet_bits = substr($binary_subnet, 0, $mask_bits);
		return $ip_net_bits === $subnet_bits;
	}

	/**
	 * Check if the client (e.g. last proxy) is allowed to send unsafe headers.
	 * This uses the `TRUSTED_PROXY` environment variable or the `trusted_sources` configuration option to get an array of the authorized ranges,
	 * The connection IP is obtained from the `CONN_REMOTE_ADDR`
	 * (if available, to be robust even when using Apache mod_remoteip) or `REMOTE_ADDR` environment variables.
	 * @return bool true if the sender’s IP is in one of the ranges defined in the configuration, else false
	 */
	public static function checkTrustedIP(): bool {
		if (!FreshRSS_Context::hasSystemConf()) {
			return false;
		}
		$remoteIp = Minz_Request::connectionRemoteAddress();
		if ($remoteIp === '') {
			return false;
		}
		$trusted = getenv('TRUSTED_PROXY');
		if ($trusted != 0 && is_string($trusted)) {
			$trusted = preg_split('/\s+/', $trusted, -1, PREG_SPLIT_NO_EMPTY);
		}
		if (!is_array($trusted) || empty($trusted)) {
			$trusted = FreshRSS_Context::systemConf()->trusted_sources;
		}
		foreach ($trusted as $cidr) {
			if (self::checkCIDR($remoteIp, $cidr)) {
				return true;
			}
		}
		return false;
	}

	public static function httpAuthUser(bool $onlyTrusted = true): string {
		$auths = array_unique(array_filter(
			array_intersect_key($_SERVER, ['REMOTE_USER' => '', 'REDIRECT_REMOTE_USER' => '', 'HTTP_REMOTE_USER' => '', 'HTTP_X_WEBAUTH_USER' => '']),
			fn($value) => is_string($value) && $value !== ''
		));
		if (count($auths) > 1) {
			Minz_Log::warning('Multiple HTTP authentication headers!');
			return '';
		}

		if (!empty($_SERVER['REMOTE_USER']) && is_string($_SERVER['REMOTE_USER'])) {
			return $_SERVER['REMOTE_USER'];
		}
		if (!empty($_SERVER['REDIRECT_REMOTE_USER']) && is_string($_SERVER['REDIRECT_REMOTE_USER'])) {
			return $_SERVER['REDIRECT_REMOTE_USER'];
		}
		if (!$onlyTrusted || self::checkTrustedIP()) {
			if (!empty($_SERVER['HTTP_REMOTE_USER']) && is_string($_SERVER['HTTP_REMOTE_USER'])) {
				return $_SERVER['HTTP_REMOTE_USER'];
			}
			if (!empty($_SERVER['HTTP_X_WEBAUTH_USER']) && is_string($_SERVER['HTTP_X_WEBAUTH_USER'])) {
				return $_SERVER['HTTP_X_WEBAUTH_USER'];
			}
		}
		return '';
	}
}
