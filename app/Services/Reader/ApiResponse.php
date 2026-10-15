<?php
declare(strict_types=1);

namespace FreshRSS\Services\Reader;

use JsonException;

/** Versioned JSON responses for the reader API. */
final class ApiResponse {
	/** @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function envelope(array $data, int $status): array {
		if (isset($data['error'])) {
			$data['errorDetails'] = ['code' => $data['error'], 'status' => $status];
		}
		return ['version' => ApiContract::VERSION] + $data;
	}

	/** Authentication, request parsing and controller failures use the same recoverable JSON response.
	 * @param array<string,mixed> $data
	 */
	public static function respond(array $data, int $status = 200): never {
		$options = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
		try {
			$body = json_encode(self::envelope($data, $status), $options);
		} catch (JsonException) {
			$status = 500;
			$body = json_encode(self::envelope(['error' => 'response_encoding_failed'], $status), $options);
		}
		http_response_code($status);
		header('Content-Type: application/json; charset=UTF-8');
		header('Cache-Control: private, no-store');
		header('X-Content-Type-Options: nosniff');
		echo $body;
		exit;
	}

}
