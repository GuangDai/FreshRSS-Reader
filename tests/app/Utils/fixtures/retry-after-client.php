<?php
declare(strict_types=1);

$directory = $argv[1] ?? '';
if ($directory === '') {
	throw new InvalidArgumentException('Missing data directory');
}
putenv('DATA_PATH=' . $directory);
require dirname(__DIR__, 3) . '/bootstrap.php';
FreshRSS_Context::initSystem();
$operation = $argv[2] ?? '';
$url = 'http://127.0.0.1/retry-after-concurrent';
if ($operation === 'clean') {
	echo FreshRSS_http_Util::cleanRetryAfters(1);
} elseif ($operation === 'set') {
	echo FreshRSS_http_Util::setRetryAfter($url, '', '300');
} elseif ($operation === 'read') {
	echo FreshRSS_http_Util::getRetryAfter($url, '');
} elseif ($operation === 'stress-clean') {
	for ($i = 0; $i < 100; $i++) {
		FreshRSS_http_Util::cleanRetryAfters(1);
		usleep(1000);
	}
} elseif ($operation === 'stress-read') {
	$misses = 0;
	for ($i = 0; $i < 100; $i++) {
		if (FreshRSS_http_Util::getRetryAfter($url, '') === 0) {
			$misses++;
		}
		usleep(1000);
	}
	echo $misses;
}
