<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';
FreshRSS_Context::initSystem();
$url = $argv[1] ?? '';
$cache = $argv[2] ?? '';
if ($url === '') {
	throw new InvalidArgumentException('Missing URL');
}
FreshRSS_Context::systemConf()->internal_host_allowlist = ['127.0.0.1:' . parse_url($url, PHP_URL_PORT)];
echo json_encode(FreshRSS_http_Util::httpGet($url, $cache, 'json', ['timeout' => 2]), JSON_THROW_ON_ERROR);
