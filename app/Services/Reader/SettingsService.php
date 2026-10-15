<?php
declare(strict_types=1);

namespace FreshRSS\Services\Reader;

use DateInterval;
use Exception;
use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS_BooleanSearch;
use FreshRSS_Feed;
use FreshRSS_UserConfiguration;
use Minz_BadRequestException;

/**
 * Current-user settings exposed by the independent reader.
 * Only existing account preferences are writable; storage maintenance runs separately.
 * @phpstan-type FieldSpec array{type:'boolean'|'integer'|'string'|'integer_or_false'|'duration'|'string_list',group:string,
 *     min?:int,max?:int,options?:list<string>,unit?:string,disabledValue?:false}
 * @phpstan-type Description array{schema:array{fields:array<string,FieldSpec>,atomic:true},values:array<string,mixed>}
 */
final class SettingsService {
	public const DURATION_PATTERN = '^P(?=[0-9]|T[0-9])(?:[0-9]+Y)?(?:[0-9]+M)?(?:[0-9]+W)?(?:[0-9]+D)?'
		. '(?:T(?=[0-9])(?:[0-9]+H)?(?:[0-9]+M)?(?:[0-9]+S)?)?$';

	/** @return array<string,FieldSpec> */
	public static function fields(): array {
		return [
			'posts_per_page' => ['type' => 'integer', 'group' => 'reading', 'min' => 1, 'unit' => 'articles'],
			'default_view' => ['type' => 'string', 'group' => 'reading', 'options' => ['adaptive', 'unread', 'unread_or_favorite', 'all']],
			'auto_load_more' => ['type' => 'boolean', 'group' => 'reading'],
			'onread_jump_next' => ['type' => 'boolean', 'group' => 'reading'],
			'auto_remove_article' => ['type' => 'boolean', 'group' => 'reading'],
			'reading_confirm' => ['type' => 'boolean', 'group' => 'reading'],
			'mark_updated_article_unread' => ['type' => 'boolean', 'group' => 'reading'],
			'mark_when.article' => ['type' => 'boolean', 'group' => 'reading'],
			'mark_when.site' => ['type' => 'boolean', 'group' => 'reading'],
			'mark_when.scroll' => ['type' => 'boolean', 'group' => 'reading'],
			'mark_when.focus' => ['type' => 'boolean', 'group' => 'reading'],
			'mark_when.gone' => ['type' => 'boolean', 'group' => 'filters'],
			'mark_when.reception' => ['type' => 'boolean', 'group' => 'filters'],
			'mark_when.max_n_unread' => ['type' => 'integer_or_false', 'group' => 'filters', 'min' => 0,
				'unit' => 'articles', 'disabledValue' => false],
			'mark_when.same_title_in_feed' => ['type' => 'integer_or_false', 'group' => 'filters', 'min' => 0,
				'unit' => 'articles', 'disabledValue' => false],
			'filters.read' => ['type' => 'string_list', 'group' => 'filters'],
			'filters.star' => ['type' => 'string_list', 'group' => 'filters'],
			'hide_read_feeds' => ['type' => 'boolean', 'group' => 'navigation'],
			'display_categories' => ['type' => 'string', 'group' => 'navigation', 'options' => ['active', 'remember', 'all', 'none']],
			'show_unread_count' => ['type' => 'string', 'group' => 'navigation', 'options' => ['all', 'important', 'none']],
			'show_title_unread' => ['type' => 'boolean', 'group' => 'navigation'],
			'show_favicons' => ['type' => 'boolean', 'group' => 'navigation'],
			'icons_as_emojis' => ['type' => 'boolean', 'group' => 'navigation'],
			'lazyload' => ['type' => 'boolean', 'group' => 'media'],
			'ttl_default' => ['type' => 'integer', 'group' => 'refresh', 'min' => FreshRSS_Feed::TTL_DEFAULT,
				'max' => FreshRSS_Feed::TTL_MAX, 'unit' => 'seconds'],
			'dynamic_opml_ttl_default' => ['type' => 'integer', 'group' => 'refresh', 'min' => FreshRSS_Feed::TTL_DEFAULT,
				'max' => FreshRSS_Feed::TTL_MAX, 'unit' => 'seconds'],
			'archiving.keep_period' => ['type' => 'duration', 'group' => 'cleanup', 'unit' => 'iso8601', 'disabledValue' => false],
			'archiving.keep_max' => ['type' => 'integer_or_false', 'group' => 'cleanup', 'min' => 0,
				'unit' => 'articles', 'disabledValue' => false],
			'archiving.keep_min' => ['type' => 'integer_or_false', 'group' => 'cleanup', 'min' => 0,
				'unit' => 'articles', 'disabledValue' => false],
			'archiving.keep_favourites' => ['type' => 'boolean', 'group' => 'cleanup'],
			'archiving.keep_labels' => ['type' => 'boolean', 'group' => 'cleanup'],
			'archiving.keep_unreads' => ['type' => 'boolean', 'group' => 'cleanup'],
			'max_posts_per_rss' => ['type' => 'integer', 'group' => 'storage', 'min' => 1, 'unit' => 'articles'],
			'since_hours_posts_per_rss' => ['type' => 'integer', 'group' => 'storage', 'min' => 0, 'unit' => 'hours'],
			'html5_enable_notif' => ['type' => 'boolean', 'group' => 'notifications'],
			'html5_notif_timeout' => ['type' => 'integer', 'group' => 'notifications', 'min' => 0, 'unit' => 'seconds'],
			'good_notification_timeout' => ['type' => 'integer', 'group' => 'notifications', 'min' => 0, 'unit' => 'seconds'],
			'bad_notification_timeout' => ['type' => 'integer', 'group' => 'notifications', 'min' => 1, 'unit' => 'seconds'],
			'retrieve_extension_list' => ['type' => 'boolean', 'group' => 'privacy'],
			'send_referrer_allowlist' => ['type' => 'string_list', 'group' => 'privacy'],
		];
	}

	/** @return Description */
	public static function describe(FreshRSS_UserConfiguration $configuration): array {
		$values = [];
		$data = $configuration->toArray();
		foreach (self::fields() as $field => $spec) {
			[$key, $nested] = array_pad(explode('.', $field, 2), 2, null);
			if ($key === 'filters') {
				$values[$field] = self::filterValues($data['filters'] ?? [], $nested ?? '');
			} elseif ($nested !== null) {
				$group = $data[$key] ?? [];
				$values[$field] = is_array($group) ? ($group[$nested] ?? null) : null;
			} else {
				$values[$field] = $data[$key] ?? null;
			}
		}
		return ['schema' => ['fields' => self::fields(), 'atomic' => true], 'values' => $values];
	}

	/**
	 * Merge a partial patch into the latest file under its configuration write lock.
	 * Nested fields and filter actions from other requests remain intact.
	 * @param array<string,mixed> $values
	 * @return Description
	 * @throws ApiException
	 */
	public static function save(FreshRSS_UserConfiguration $configuration, array $values): array {
		$changes = self::validate($values);
		$saved = $configuration->mutate(static function (array $latest) use ($changes): array {
			foreach ($changes as $field => $value) {
				[$key, $nested] = array_pad(explode('.', $field, 2), 2, null);
				if ($key === 'filters' && is_array($value) && array_is_list($value) && is_array_values_string($value)) {
					$latest['filters'] = self::mergeFilterAction($latest['filters'] ?? [], $nested ?? '', $value);
				} elseif ($nested !== null) {
					$group = $latest[$key] ?? [];
					$group = is_array($group) ? $group : [];
					$group[$nested] = $value;
					$latest[$key] = $group;
				} else {
					$latest[$key] = $value;
				}
			}
			return $latest;
		});
		if (!$saved) {
			throw new ApiException('write_failed', 500, ['atomic' => true]);
		}
		return self::describe($configuration);
	}

	/**
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 * @throws ApiException
	 */
	public static function validate(array $values): array {
		if ($values === [] || array_is_list($values)) {
			throw new ApiException('empty_update');
		}
		$fields = self::fields();
		$result = [];
		foreach ($values as $field => $value) {
			if (!isset($fields[$field])) {
				throw new ApiException('invalid_field', details: ['field' => $field]);
			}
			$spec = $fields[$field];
			if (($spec['type'] === 'boolean' && !is_bool($value)) || ($spec['type'] === 'integer' && !is_int($value)) ||
				($spec['type'] === 'string' && !is_string($value)) ||
				($spec['type'] === 'integer_or_false' && !is_int($value) && $value !== false)) {
				throw new ApiException('invalid_field', details: ['field' => $field]);
			}
			if ($spec['type'] === 'duration') {
				self::validateDuration($field, $value);
			} elseif ($spec['type'] === 'string_list') {
				$value = self::normaliseStrings($field, $value);
			}
			if ((is_int($value) && ((isset($spec['min']) && $value < $spec['min']) || (isset($spec['max']) && $value > $spec['max']))) ||
				(isset($spec['options']) && !in_array($value, $spec['options'], true))) {
				throw new ApiException('field_out_of_range', details: ['field' => $field]);
			}
			$result[$field] = $value;
		}
		return $result;
	}

	/** @throws ApiException */
	private static function validateDuration(string $field, mixed $value): void {
		if ($value === false) {
			return;
		}
		if (!is_string($value) || preg_match('/' . self::DURATION_PATTERN . '/D', $value) !== 1) {
			throw new ApiException('invalid_field', details: ['field' => $field]);
		}
		// Keep each component in PHP's integer range before DateInterval interprets it.
		preg_match_all('/[0-9]+/', $value, $matches);
		$maximum = (string)PHP_INT_MAX;
		foreach ($matches[0] as $count) {
			$count = ltrim($count, '0');
			if (strlen($count) > strlen($maximum) || (strlen($count) === strlen($maximum) && strcmp($count, $maximum) > 0)) {
				throw new ApiException('invalid_field', details: ['field' => $field]);
			}
		}
		try {
			new DateInterval($value);
		} catch (Exception $exception) {
			throw new ApiException('invalid_field', details: ['field' => $field], previous: $exception);
		}
	}

	/** @return list<string>
	 * @throws ApiException
	 */
	private static function normaliseStrings(string $field, mixed $value): array {
		if (!is_array($value) || !array_is_list($value) || !is_array_values_string($value)) {
			throw new ApiException('invalid_field', details: ['field' => $field]);
		}
		$result = [];
		foreach ($value as $string) {
			$string = trim($string);
			if (str_starts_with($field, 'filters.') && $string !== '') {
				try {
					$search = new FreshRSS_BooleanSearch($string, expandUserQueries: false);
					$string = $search->toString(expandUserQueries: false);
				} catch (Minz_BadRequestException $exception) {
					throw new ApiException('invalid_filter', details: ['field' => $field], previous: $exception);
				}
			}
			if ($string !== '' && !in_array($string, $result, true)) {
				$result[] = $string;
			}
		}
		return $result;
	}

	/** @return list<string> */
	private static function filterValues(mixed $filters, string $action): array {
		$result = [];
		if (!is_array($filters)) {
			return $result;
		}
		foreach (array_reverse($filters) as $filter) {
			if (is_array($filter) && is_string($filter['search'] ?? null) && is_array($filter['actions'] ?? null) &&
				in_array($action, $filter['actions'], true)) {
				$result[] = $filter['search'];
			}
		}
		return $result;
	}

	/**
	 * Preserve unrelated actions and unknown filter metadata while replacing one account action.
	 * @param list<string> $searches
	 * @return list<mixed>
	 */
	private static function mergeFilterAction(mixed $filters, string $action, array $searches): array {
		$result = [];
		foreach (is_array($filters) ? $filters : [] as $filter) {
			if (!is_array($filter) || !is_array($filter['actions'] ?? null) || !is_array_values_string($filter['actions'])) {
				$result[] = $filter;
				continue;
			}
			$filter['actions'] = array_values(array_filter($filter['actions'], static fn(string $existing): bool => $existing !== $action));
			$index = is_string($filter['search'] ?? null) ? array_search($filter['search'], $searches, true) : false;
			if ($index !== false) {
				$filter['actions'][] = $action;
				unset($searches[$index]);
			}
			if ($filter['actions'] !== []) {
				$result[] = $filter;
			}
		}
		foreach (array_reverse($searches) as $search) {
			$result[] = ['search' => $search, 'actions' => [$action]];
		}
		return $result;
	}
}
