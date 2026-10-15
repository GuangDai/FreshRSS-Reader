<?php
declare(strict_types=1);

namespace FreshRSS\Utils\Http;

use FreshRSS\Services\Reader\EntryPresenter;
use FreshRSS_Context;
use InvalidArgumentException;
use LengthException;
use RuntimeException;
use UnexpectedValueException;

final class ApiInput {
	/** Bound listing requests without changing continuation or write operations. */
	public static function pageSize(mixed $requested, int $maximum): int {
		$maximum = max(1, $maximum);
		if (!is_numeric($requested)) {
			return min(20, $maximum);
		}
		// Compare before casting, so very large numeric strings cannot overflow to a negative integer.
		if ((float)$requested >= $maximum) {
			return $maximum;
		}
		return max(1, (int)$requested);
	}
	/** PHP consumes multipart bodies before php://input can enforce the limit; these APIs do not accept uploads.
	 * @param array<array-key,mixed> $server
	 * @throws LengthException
	 * @throws UnexpectedValueException
	 */
	public static function checkRequestHeaders(array $server, int $maximum): void {
		$type = $server['CONTENT_TYPE'] ?? '';
		if (is_string($type) && str_starts_with(strtolower(trim($type)), 'multipart/')) {
			throw new UnexpectedValueException('unsupported_media_type');
		}
		$length = $server['CONTENT_LENGTH'] ?? '';
		if (is_string($length) && ctype_digit($length)) {
			$length = ltrim($length, '0');
			$limit = (string)max(1, $maximum);
			if (strlen($length) > strlen($limit) || (strlen($length) === strlen($limit) && strcmp($length, $limit) > 0)) {
				throw new LengthException('request_too_large');
			}
		}
	}

	/** Read one extra byte so oversized bodies are rejected instead of silently truncated.
	 * @throws LengthException
	 * @throws RuntimeException
	 * @param resource $stream
	 */
	public static function readBody($stream, int $maximum): string {
		$maximum = max(1, min(PHP_INT_MAX - 1, $maximum));
		$body = stream_get_contents($stream, $maximum + 1);
		if ($body === false) {
			throw new RuntimeException('request_read_failed');
		}
		if (strlen($body) > $maximum) {
			throw new LengthException('request_too_large');
		}
		return $body;
	}

	/** Preserve repeated form fields without allocating an array for every field.
	 * @throws LengthException
	 * @return list<string>
	 */
	public static function formValues(string $body, string $name, int $maximum): array {
		$result = [];
		$offset = 0;
		$length = strlen($body);
		while ($offset < $length) {
			$end = strpos($body, '&', $offset);
			$end = $end === false ? $length : $end;
			$field = substr($body, $offset, $end - $offset);
			$equals = strpos($field, '=');
			if ($equals !== false && urldecode(substr($field, 0, $equals)) === $name) {
				if (count($result) >= $maximum) {
					throw new LengthException('batch_too_large');
				}
				$result[] = urldecode(substr($field, $equals + 1));
			}
			$offset = $end + 1;
		}
		return $result;
	}

	/** Fever historically ignores invalid IDs; keep that behaviour within explicit resource limits.
	 * @throws LengthException
	 * @return list<numeric-string>
	 */
	public static function commaIds(string $value, int $maximum, int $maxBytes): array {
		if (strlen($value) > $maxBytes || substr_count($value, ',') >= $maximum) {
			throw new LengthException('batch_too_large');
		}
		$result = [];
		foreach (explode(',', $value) as $id) {
			if (ctype_digit($id) && strlen($id) <= 19 && strcmp(str_pad($id, 19, '0', STR_PAD_LEFT), '9223372036854775807') <= 0) {
				$result[] = $id;
			}
		}
		return $result;
	}

	/** Numeric literals avoid the database placeholder ceiling for intersecting Fever filters.
	 * @param array<string|int> $ids
	 * @throws InvalidArgumentException
	 */
	public static function sqlIdList(array $ids): string {
		$result = [];
		foreach ($ids as $id) {
			$id = (string)$id;
			if (!ctype_digit($id) || strlen($id) > 19 || strcmp(str_pad($id, 19, '0', STR_PAD_LEFT), '9223372036854775807') > 0) {
				throw new InvalidArgumentException('invalid_id');
			}
			$result[] = ltrim($id, '0') ?: '0';
		}
		return $result === [] ? 'NULL' : implode(',', array_unique($result));
	}

	/** @return array{maxBatchItems:int,maxRequestBytes:int} */
	public static function limits(): array {
		$limits = FreshRSS_Context::systemConf()->limits;
		return ['maxBatchItems' => max(1, $limits['api_max_batch_items'] ?? 1000),
			'maxRequestBytes' => max(1, $limits['api_max_request_bytes'] ?? 1048576)];
	}

	/** @throws LengthException
	 * @throws InvalidArgumentException
	 * @param array<string,mixed> $params
	 * @return array{ids:list<numeric-string>,field:'read'|'starred',value:bool}
	 */
	public static function stateBatch(array $params, int $maximum): array {
		$ids = $params['ids'] ?? null;
		if (!is_array($ids) || !array_is_list($ids) || $ids === []) {
			throw new InvalidArgumentException('invalid_ids');
		}
		if (count($ids) > $maximum) {
			throw new LengthException('batch_too_large');
		}
		if (array_key_exists('read', $params) === array_key_exists('starred', $params)) {
			throw new InvalidArgumentException('invalid_state');
		}
		$field = array_key_exists('read', $params) ? 'read' : 'starred';
		if (!is_bool($params[$field])) {
			throw new InvalidArgumentException('invalid_state');
		}
		$result = [];
		foreach ($ids as $id) {
			if (!is_string($id) || EntryPresenter::entryId($id) === null) {
				throw new InvalidArgumentException('invalid_id');
			}
			/** @var numeric-string $id */
			$result[] = $id;
		}
		return ['ids' => array_values(array_unique($result)), 'field' => $field, 'value' => $params[$field]];
	}

}
