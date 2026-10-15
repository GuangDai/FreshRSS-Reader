<?php
declare(strict_types=1);

namespace FreshRSS\Services\Subscriptions;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS_DatabaseDAO;

final class NameValidator {
	/** Stored names must fit after encoding; feeds and categories share the same database bound.
	 * @throws ApiException
	 */
	public static function validate(mixed $value): string {
		if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8')) {
			throw new ApiException('invalid_name');
		}
		$value = htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		if (strlen($value) > FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE) {
			throw new ApiException('name_too_long', details: ['maxBytes' => FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE]);
		}
		return $value;
	}
}
