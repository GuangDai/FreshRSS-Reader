<?php
declare(strict_types=1);

// Works before installation and after login is enabled; never modifies application data.
$url = getenv('FRESHRSS_HEALTH_URL') ?: 'http://127.0.0.1:80/i/';
$request = curl_init($url);
if ($request === false) {
	exit(1);
}
curl_setopt_array($request, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_MAXREDIRS => 5,
	CURLOPT_CONNECTTIMEOUT => 2,
	CURLOPT_TIMEOUT => 5,
	CURLOPT_PROXY => '',
]);
$body = curl_exec($request);
$status = curl_getinfo($request, CURLINFO_HTTP_CODE);
if ($status !== 200 || !is_string($body) || !str_contains($body, '<html') || !str_contains($body, 'FreshRSS')) {
	fwrite(STDERR, 'FreshRSS HTTP health check failed: ' . $status . PHP_EOL);
	exit(1);
}
