<?php
declare(strict_types=1);

$path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
if ($path === '/slow') {
	$counter = getenv('FRESHRSS_HTTP_TEST_COUNTER');
	if (is_string($counter) && $counter !== '') {
		file_put_contents($counter, (string)(1 + (int)file_get_contents($counter)), LOCK_EX);
	}
	usleep(200000);
	echo str_repeat('complete response.', 4096);
} elseif ($path === '/not-modified') {
	http_response_code(304);
} elseif ($path === '/throttled') {
	header('Retry-After: 3');
	http_response_code(429);
	echo 'slow down';
} elseif ($path === '/failed') {
	http_response_code(500);
	echo 'failed response';
} elseif ($path === '/small') {
	echo 'ok';
} elseif ($path === '/declared') {
	header('Content-Length: 4096');
	echo str_repeat('a', 4096);
} elseif ($path === '/gzip-chunked') {
	header('Content-Encoding: gzip');
	header('Transfer-Encoding: chunked');
	$payload = gzencode(str_repeat('a', 4096));
	if (is_string($payload)) {
		foreach (str_split($payload, 8) as $chunk) {
			echo dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
		}
		echo "0\r\n\r\n";
	}
} elseif ($path === '/large') {
	header('Content-Encoding: gzip');
	echo gzencode(str_repeat('a', 4096));
} elseif ($path === '/loop') {
	header('Location: /loop');
} elseif ($path === '/redirect') {
	header('Location: http://localhost:' . (is_string($_SERVER['SERVER_PORT'] ?? null) ? $_SERVER['SERVER_PORT'] : '') . '/headers');
} else {
	header('Content-Type: application/json');
	echo json_encode(getallheaders());
}
