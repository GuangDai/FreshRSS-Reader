<?php
declare(strict_types=1);

namespace FreshRSS\Services\Reader;

/**
 * @phpstan-type PreferenceSpec array{type:'enum'|'integer'|'boolean',default:string|int|bool,options?:list<string>,min?:int,max?:int,unit?:string,group?:string}
 */
final class Preferences {
	/** MetaCubeXD's selectable themes, using daisyUI 5.7.17's corresponding tokens. */
	public const THEMES = [
		'acid', 'aqua', 'autumn', 'black', 'bumblebee', 'business', 'cmyk', 'coffee',
		'corporate', 'cupcake', 'cyberpunk', 'dark', 'dim', 'dracula', 'emerald', 'fantasy',
		'forest', 'garden', 'halloween', 'lemonade', 'light', 'lofi', 'luxury', 'night',
		'nord', 'pastel', 'retro', 'sunset', 'synthwave', 'valentine', 'winter', 'wireframe',
	];
	/** @var array<string,PreferenceSpec> */
	private const SCHEMA = [
		'layout' => ['type' => 'enum', 'default' => 'split', 'options' => ['split', 'list', 'cards']],
		'style' => ['type' => 'enum', 'default' => 'calm', 'options' => ['calm', 'paper', 'contrast']],
		'theme' => ['type' => 'enum', 'default' => 'sunset', 'options' => ['auto', ...self::THEMES]],
		'lightTheme' => ['type' => 'enum', 'default' => 'nord', 'options' => self::THEMES],
		'darkTheme' => ['type' => 'enum', 'default' => 'sunset', 'options' => self::THEMES],
		'density' => ['type' => 'enum', 'default' => 'comfortable', 'options' => ['comfortable', 'compact']],
		'fontSize' => ['type' => 'integer', 'default' => 18, 'min' => 8, 'max' => 96, 'unit' => 'px'],
		'lineHeight' => ['type' => 'integer', 'default' => 180, 'min' => 140, 'max' => 220, 'unit' => '%'],
		'readingWidth' => ['type' => 'integer', 'default' => 800, 'min' => 280, 'max' => 3840, 'unit' => 'px'],
		'sidebarWidth' => ['type' => 'integer', 'default' => 240, 'min' => 160, 'max' => 600, 'unit' => 'px'],
		'listWidth' => ['type' => 'integer', 'default' => 360, 'min' => 240, 'max' => 1000, 'unit' => 'px'],
		'remoteImages' => ['type' => 'boolean', 'default' => true],
		'pageSize' => ['type' => 'integer', 'default' => 50, 'min' => 1, 'max' => 200, 'unit' => 'articles'],
		'cacheArticles' => ['type' => 'integer', 'default' => 12, 'min' => 0, 'max' => 200, 'unit' => 'articles'],
		'cacheMiB' => ['type' => 'integer', 'default' => 4, 'min' => 0, 'max' => 64, 'unit' => 'MiB'],
		'requestTimeout' => ['type' => 'integer', 'default' => 15, 'min' => 0, 'max' => 120, 'unit' => 'seconds'],
		'refreshInterval' => ['type' => 'integer', 'default' => 120, 'min' => 0, 'max' => 86400, 'unit' => 'seconds'],
	];

	/** @param array<string,int> $limits
	 * @return array<string,PreferenceSpec>
	 */
	public static function schema(array $limits = []): array {
		$schema = self::SCHEMA;
		foreach (['pageSize' => 'max_page_size', 'cacheArticles' => 'max_cached_articles', 'cacheMiB' => 'max_cache_mib',
			'requestTimeout' => 'max_request_timeout', 'refreshInterval' => 'max_refresh_interval'] as $field => $limit) {
			if (isset($limits[$limit])) {
				$schema[$field]['max'] = max($schema[$field]['min'], $limits[$limit]);
				$schema[$field]['default'] = min($schema[$field]['default'], $schema[$field]['max']);
			}
		}
		foreach ($schema as $key => &$spec) {
			$spec['group'] = in_array($key, ['layout', 'style', 'theme', 'lightTheme', 'darkTheme', 'density'], true) ? 'appearance' :
				(in_array($key, ['fontSize', 'lineHeight', 'readingWidth', 'sidebarWidth', 'listWidth'], true) ? 'reading' :
				($key === 'remoteImages' ? 'privacy' : 'performance'));
		}
		return $schema;
	}

	/** @param array<array-key,mixed> $values
	 * @param array<string,PreferenceSpec> $schema
	 * @return array<string,string|int|bool>
	 */
	public static function preferences(array $values, array $schema = []): array {
		$schema = $schema ?: self::schema();
		$result = [];
		foreach ($schema as $key => $spec) {
			$result[$key] = $spec['default'];
			$value = $values[$key] ?? null;
			if ($spec['type'] === 'enum' && is_string($value) && in_array($value, $spec['options'] ?? [], true)) {
				$result[$key] = $value;
			} elseif ($spec['type'] === 'boolean' && is_bool($value)) {
				$result[$key] = $value;
			} elseif ($spec['type'] === 'integer') {
				$number = is_int($value) ? $value : null;
				if ($number !== null) {
					$result[$key] = max($spec['min'] ?? 0, min($spec['max'] ?? PHP_INT_MAX, $number));
				}
			}
		}
		return $result;
	}

	/** Validate writes explicitly; normalisation is reserved for old or missing stored preferences.
	 * @param array<array-key,mixed> $values
	 * @param array<string,PreferenceSpec> $schema
	 * @return list<string>
	 */
	public static function preferenceErrors(array $values, array $schema): array {
		$errors = [];
		foreach ($values as $key => $value) {
			$spec = $schema[$key] ?? null;
			if ($spec === null || ($spec['type'] === 'integer' && (!is_int($value) || $value < ($spec['min'] ?? 0) || $value > ($spec['max'] ?? PHP_INT_MAX))) ||
				($spec['type'] === 'boolean' && !is_bool($value)) ||
				($spec['type'] === 'enum' && !in_array($value, $spec['options'] ?? [], true))) {
				$errors[] = (string)$key;
			}
		}
		return $errors;
	}

}
