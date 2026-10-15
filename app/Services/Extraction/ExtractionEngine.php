<?php
declare(strict_types=1);

namespace FreshRSS\Services\Extraction;

use DOMDocument;
use DOMElement;
use DOMNameSpaceNode;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use FreshRSS\Utils\Html\CssSelector;
use FreshRSS_SimplePieCustom;
use FreshRSS_dotNotation_Util;
use InvalidArgumentException;
use JsonException;

/**
 * Declarative extraction from supplied UTF-8 HTML or JSON. Never fetches URLs.
 *
 * @phpstan-type Transform array{type:string,input_format?:string,output_format?:string,timezone?:string}
 * @phpstan-type ExtractionCandidate array{type:string,selector:string,mode?:string,attribute?:string,all?:bool,separator?:string,
 *     index?:int,unique?:bool,postprocess?:list<Transform>}
 * @phpstan-type Diagnostic array{field:string,candidate:int,type:string,status:string,reason:string,matches:int,output_bytes:int}
 * @phpstan-type ExtractionResult array{values:array<string,string>,formats:array<string,string>,errors:list<string>,diagnostics:list<Diagnostic>,success:bool,
 *     items?:array<string,list<string>>}
 * @phpstan-type ExtractionRecipe array{format?:string,fields:array<string,list<ExtractionCandidate>>,remove?:list<string>,required?:list<string>,max_source_bytes?:int,max_output_bytes?:int,max_matches?:int}
 */
final class ExtractionEngine {
	public const MAX_SOURCE_BYTES = 8388608;
	public const MAX_OUTPUT_BYTES = 2097152;
	public const MAX_MATCHES = 1000;
	public const MAX_FIELDS = 32;
	public const MAX_CANDIDATES = 16;
	public const MAX_SELECTOR_BYTES = 2048;
	public const JSON_DEPTH = 64;
	public const MAX_TRANSFORMS = 8;
	public const MAX_ERRORS = 64;
	public const MAX_ERROR_BYTES = 256;

	/** @return array<string,ExtractionRecipe> */
	public static function presets(): array {
		return ['article' => [
			'format' => 'html',
			'fields' => [
				'title' => [
					['type' => 'css', 'selector' => 'meta[property="og:title"]', 'mode' => 'attribute', 'attribute' => 'content'],
					['type' => 'jsonld', 'selector' => 'headline'],
					['type' => 'css', 'selector' => 'h1'],
					['type' => 'css', 'selector' => 'title'],
				],
				'content' => [
					['type' => 'css', 'selector' => 'article', 'mode' => 'html'],
					['type' => 'css', 'selector' => 'main', 'mode' => 'html'],
					['type' => 'jsonld', 'selector' => 'articleBody'],
				],
				'author' => [
					['type' => 'css', 'selector' => 'meta[name="author"]', 'mode' => 'attribute', 'attribute' => 'content'],
					['type' => 'jsonld', 'selector' => 'author.name'],
					['type' => 'jsonld', 'selector' => 'author[0].name'],
				],
				'link' => [
					['type' => 'css', 'selector' => 'link[rel="canonical"]', 'mode' => 'attribute', 'attribute' => 'href'],
					['type' => 'css', 'selector' => 'meta[property="og:url"]', 'mode' => 'attribute', 'attribute' => 'content'],
				],
			],
			'remove' => ['nav', 'aside', '.advertisement'],
		], 'json_item' => [
			'format' => 'json',
			'fields' => [
				'title' => [['type' => 'json', 'selector' => 'title']],
				'content' => [
					['type' => 'json', 'selector' => 'content_html', 'mode' => 'html'],
					['type' => 'json', 'selector' => 'content_text'],
				],
				'author' => [
					['type' => 'json', 'selector' => 'authors[0].name'],
					['type' => 'json', 'selector' => 'author.name'],
				],
				'link' => [['type' => 'json', 'selector' => 'url']],
			],
		]];
	}

	/** @param array<array-key,mixed> $recipe
	 * @return list<string>
	 */
	public static function validate(array $recipe, bool $checkSelectors = false): array {
		$errors = [];
		$allowed = ['format', 'fields', 'remove', 'required', 'max_source_bytes', 'max_output_bytes', 'max_matches'];
		foreach (array_keys($recipe) as $key) {
			if (!in_array($key, $allowed, true)) {
				$errors[] = 'Unknown recipe option: ' . $key;
			}
		}
		$format = array_key_exists('format', $recipe) ? $recipe['format'] : 'html';
		if (!in_array($format, ['html', 'json'], true)) {
			$errors[] = 'format must be html or json';
		}
		foreach (['max_source_bytes' => self::MAX_SOURCE_BYTES, 'max_output_bytes' => self::MAX_OUTPUT_BYTES, 'max_matches' => self::MAX_MATCHES] as $key => $max) {
			if (array_key_exists($key, $recipe) && (!is_int($recipe[$key]) || $recipe[$key] < 1 || $recipe[$key] > $max)) {
				$errors[] = $key . ' must be an integer between 1 and ' . $max;
			}
		}
		$fields = $recipe['fields'] ?? null;
		if (!is_array($fields) || $fields === [] || count($fields) > self::MAX_FIELDS) {
			$errors[] = 'fields must contain between 1 and ' . self::MAX_FIELDS . ' named fields';
			return self::boundedErrors($errors);
		}
		foreach ($fields as $name => $candidates) {
			if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1 || !is_array($candidates)
				|| !array_is_list($candidates) || $candidates === [] || count($candidates) > self::MAX_CANDIDATES) {
				$errors[] = 'Invalid field name or candidate list: ' . $name;
				continue;
			}
			foreach ($candidates as $candidate) {
				if (!is_array($candidate)) {
					$errors[] = $name . ': candidate must be an object';
					continue;
				}
				foreach (array_keys($candidate) as $key) {
					if (!in_array($key, ['type', 'selector', 'mode', 'attribute', 'all', 'separator', 'index', 'unique', 'postprocess'], true)) {
						$errors[] = $name . ': unknown candidate option ' . $key;
					}
				}
				$type = $candidate['type'] ?? null;
				$selector = $candidate['selector'] ?? null;
				$mode = array_key_exists('mode', $candidate) ? $candidate['mode'] : 'text';
				if (!in_array($type, $format === 'json' ? ['json'] : ['css', 'xpath', 'jsonld'], true)) {
					$errors[] = $name . ': unsupported candidate type for format';
				}
				if (!self::validString($selector) || trim($selector) === '') {
					$errors[] = $name . ': selector must be a non-empty string of at most ' . self::MAX_SELECTOR_BYTES . ' bytes';
				}
				if (!in_array($mode, ['text', 'html', 'attribute'], true)
					|| ($mode === 'attribute' && (!in_array($type, ['css', 'xpath'], true)
						|| !self::validString($candidate['attribute'] ?? null)))) {
					$errors[] = $name . ': invalid mode or missing attribute';
				}
				if (array_key_exists('attribute', $candidate) && !self::validString($candidate['attribute'])) {
					$errors[] = $name . ': attribute must be a string';
				}
				if (array_key_exists('all', $candidate) && !is_bool($candidate['all'])) {
					$errors[] = $name . ': all must be boolean';
				}

				if (array_key_exists('index', $candidate) && (!is_int($candidate['index']) || $candidate['index'] < 0
					|| $candidate['index'] >= self::MAX_MATCHES || !in_array($type, ['css', 'xpath'], true) || ($candidate['all'] ?? false) === true)) {
					$errors[] = $name . ': index requires a non-negative DOM match index and all=false';
				}
				if (array_key_exists('unique', $candidate) && (!is_bool($candidate['unique'])
					|| ($candidate['unique'] && ($candidate['all'] ?? false) !== true))) {
					$errors[] = $name . ': unique must be boolean and requires all=true when enabled';
				}
				if (array_key_exists('separator', $candidate) && !self::validString($candidate['separator'], true)) {
					$errors[] = $name . ': invalid separator';
				}
				foreach (self::validateTransforms($candidate['postprocess'] ?? [], $mode) as $error) {
					$errors[] = $name . ': ' . $error;
				}
				if (array_key_exists('postprocess', $candidate) && $candidate['postprocess'] === null) {
					$errors[] = $name . ': postprocess must be a list';
				}
			}
		}
		foreach (['remove', 'required'] as $key) {
			$list = array_key_exists($key, $recipe) ? $recipe[$key] : [];
			if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_FIELDS) {
				$errors[] = $key . ' must be a list of at most ' . self::MAX_FIELDS . ' strings';
				continue;
			}
			if ($key === 'remove' && $format === 'json' && $list !== []) {
				$errors[] = 'remove requires HTML format';
			}
			foreach ($list as $value) {
				if (!self::validString($value)
					|| ($key === 'required' && !array_key_exists($value, $fields))) {
					$errors[] = 'Invalid ' . $key . ' value';
				}
			}
		}
		if ($errors === [] && $checkSelectors) {
			/** @var ExtractionRecipe $recipe Validated above. */
			$errors = self::selectorErrors($recipe);
		}
		return self::boundedErrors($errors);
	}

	/** @param ExtractionRecipe $recipe
	 * @return list<string>
	 */
	private static function selectorErrors(array $recipe): array {
		$previous = libxml_use_internal_errors(true);
		$errors = [];
		try {
			$xpath = new DOMXPath(new DOMDocument());
			foreach ($recipe['fields'] as $field => $candidates) {
				foreach ($candidates as $candidate) {
					try {
						if ($candidate['type'] === 'css') {
							self::css($candidate['selector']);
						} elseif ($candidate['type'] === 'xpath') {
							self::query($xpath, $candidate['selector']);
						}
					} catch (InvalidArgumentException $exception) {
						$errors[] = $field . ': ' . $exception->getMessage();
					}
				}
			}
			foreach ($recipe['remove'] ?? [] as $selector) {
				try {
					self::css($selector);
				} catch (InvalidArgumentException $exception) {
					$errors[] = 'remove: ' . $exception->getMessage();
				}
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		return $errors;
	}

	/**
	 * Text values are unescaped UTF-8; HTML values are sanitised. No field is silently truncated.
	 * @param array<array-key,mixed> $recipe
	 * @return ExtractionResult
	 */
	public static function extract(string $source, array $recipe, string $baseUrl = '', bool $includeItems = false): array {
		$errors = self::validate($recipe);
		if ($errors !== []) {
			return self::failure($errors);
		}
		/** @var ExtractionRecipe $recipe Validated above. */
		if (strlen($source) > ($recipe['max_source_bytes'] ?? self::MAX_SOURCE_BYTES)) {
			return self::failure(['Source exceeds max_source_bytes']);
		}
		if ($source === '' || !mb_check_encoding($source, 'UTF-8') || str_contains($source, "\0")) {
			return self::failure(['Source must be non-empty UTF-8 without NUL bytes']);
		}
		$removeExpressions = [];
		try {
			foreach ($recipe['remove'] ?? [] as $selector) {
				$removeExpressions[] = self::css($selector);
			}
		} catch (InvalidArgumentException $exception) {
			return self::failure(['remove: ' . $exception->getMessage()]);
		}
		$previous = libxml_use_internal_errors(true);
		try {
			$xpath = null;
			$json = null;
			$jsonLd = null;
			if (($recipe['format'] ?? 'html') === 'json') {
				$json = json_decode($source, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
			} else {
				libxml_clear_errors();
				$doc = new DOMDocument();
				if (!$doc->loadHTML("\xEF\xBB\xBF" . $source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
					return self::failure(['Could not parse HTML']);
				}
				foreach (libxml_get_errors() as $error) {
					if ($error->level === LIBXML_ERR_FATAL) {
						return self::failure(['HTML exceeds parser limits or is fatally malformed']);
					}
				}
				$xpath = new DOMXPath($doc);
			}
			$values = [];
			$formats = [];
			$diagnostics = [];
			$selectedItems = [];
			$success = true;
			foreach ($recipe['fields'] as $name => $candidates) {
				$hadError = false;
				foreach ($candidates as $index => $candidate) {
					$matches = 0;
					$items = [];
					try {
						$value = self::candidate($candidate, $xpath, $json, $jsonLd, $recipe, $baseUrl, $matches, $removeExpressions, $includeItems, $items);
						$diagnostics[] = [
							'field' => $name, 'candidate' => $index, 'type' => $candidate['type'],
							'status' => $value === '' ? 'empty' : 'matched',
							'reason' => $value !== '' ? 'selected' : ($matches === 0 ? 'no_match' : 'empty_after_processing'),
							'matches' => $matches, 'output_bytes' => strlen($value),
						];
						if ($value !== '') {
							$values[$name] = $value;
							if ($includeItems) {
								$selectedItems[$name] = $items;
							}
							$formats[$name] = $candidate['mode'] ?? 'text';
							break;
						}
					} catch (InvalidArgumentException $exception) {
						$hadError = true;
						$reason = mb_strcut($exception->getMessage(), 0, self::MAX_ERROR_BYTES, 'UTF-8');
						$errors[] = $name . ': ' . $reason;
						$diagnostics[] = [
							'field' => $name, 'candidate' => $index, 'type' => $candidate['type'],
							'status' => 'error', 'reason' => $reason, 'matches' => $matches, 'output_bytes' => 0,
						];
					}
				}
				if (!isset($values[$name]) && in_array($name, $recipe['required'] ?? [], true)) {
					$errors[] = $name . ': no non-empty value found';
					$hadError = true;
				}
				if ($hadError && !isset($values[$name])) {
					$success = false;
				}
			}
			return ['values' => $values, 'formats' => $formats, 'errors' => self::boundedErrors($errors),
				'diagnostics' => $diagnostics, 'success' => $success] + ($includeItems ? ['items' => $selectedItems] : []);
		} catch (JsonException $exception) {
			return self::failure(['Invalid JSON: ' . $exception->getMessage()]);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}

	/** @param list<string> $errors
	 * @return ExtractionResult
	 */
	private static function failure(array $errors): array {
		return ['values' => [], 'formats' => [], 'errors' => self::boundedErrors($errors), 'diagnostics' => [], 'success' => false];
	}

	/** @param list<string> $errors
	 * @return list<string>
	 */
	private static function boundedErrors(array $errors): array {
		$errors = array_values(array_unique($errors));
		$truncated = count($errors) > self::MAX_ERRORS;
		$errors = array_slice($errors, 0, $truncated ? self::MAX_ERRORS - 1 : self::MAX_ERRORS);
		$errors = array_map(static fn(string $error): string => mb_strcut($error, 0, self::MAX_ERROR_BYTES, 'UTF-8'), $errors);
		if ($truncated) {
			$errors[] = 'Further errors omitted';
		}
		return $errors;
	}

	/** @param ExtractionCandidate $candidate
	 * @param list<array<array-key,mixed>>|null $jsonLd
	 * @param ExtractionRecipe $recipe
	 * @param list<string> $removeExpressions
	 * @param list<string> $items
	 * @throws InvalidArgumentException
	 */
	private static function candidate(array $candidate, ?DOMXPath $xpath, mixed $json, ?array &$jsonLd,
		array $recipe, string $baseUrl, int &$matches, array $removeExpressions, bool $includeItems, array &$items): string {
		$parts = [];
		$bytes = 0;
		$maxOutput = $recipe['max_output_bytes'] ?? self::MAX_OUTPUT_BYTES;
		$mode = $candidate['mode'] ?? 'text';
		$separator = $candidate['separator'] ?? "\n";
		$seen = [];
		$htmlSanitised = false;
		$maxMatches = $recipe['max_matches'] ?? self::MAX_MATCHES;
		if ($candidate['type'] === 'json') {
			$value = self::jsonValue($json, $candidate['selector'], $maxOutput);
			$matches = $value === '' ? 0 : 1;
			self::append($parts, $value, $bytes, $maxOutput, $separator);
		} elseif ($xpath !== null && $candidate['type'] === 'jsonld') {
			$jsonLd ??= self::jsonLd($xpath, $maxMatches);
			foreach ($jsonLd as $object) {
				$value = self::jsonValue($object, $candidate['selector'], $maxOutput);
				if (trim($value) !== '') {
					$matches++;
					if ($candidate['unique'] ?? false) {
						if (isset($seen[$value])) {
							continue;
						}
						$seen[$value] = true;
					}
					self::append($parts, $value, $bytes, $maxOutput, $separator);
					if (!($candidate['all'] ?? false)) {
						break;
					}
				}
			}
		} elseif ($xpath !== null) {
			$expression = $candidate['type'] === 'css' ? self::css($candidate['selector']) : $candidate['selector'];
			$nodes = self::query($xpath, $expression);
			$matches = $nodes->length;
			if ($nodes->length > $maxMatches) {
				throw new InvalidArgumentException('Matched nodes exceed max_matches');
			}
			if (isset($candidate['index'])) {
				$matches = $candidate['index'] < $nodes->length ? 1 : 0;
			}
			foreach ($nodes as $index => $node) {
				if (isset($candidate['index']) && $index !== $candidate['index']) {
					continue;
				}
				if (!$node instanceof DOMNode) {
					continue;
				}
				$part = $mode === 'attribute'
					? ($node instanceof DOMElement ? $node->getAttribute($candidate['attribute'] ?? '') : '')
					: self::nodeValue($node, $mode, $removeExpressions);
				if ($mode === 'html' && !($candidate['all'] ?? false)) {
					if (strlen($part) > $maxOutput) {
						throw new InvalidArgumentException('Value exceeds max_output_bytes');
					}
					$part = FreshRSS_SimplePieCustom::sanitizeHTML($part, $baseUrl);
					$htmlSanitised = true;
				}
				if (trim($part) !== '') {
					if ($candidate['unique'] ?? false) {
						if (isset($seen[$part])) {
							continue;
						}
						$seen[$part] = true;
					}
					self::append($parts, $part, $bytes, $maxOutput, $separator);
					if (!($candidate['all'] ?? false)) {
						break;
					}
				}
			}
		}
		$value = trim(implode($separator, $parts));
		$maxOutput = $recipe['max_output_bytes'] ?? self::MAX_OUTPUT_BYTES;
		if (strlen($value) > $maxOutput) {
			throw new InvalidArgumentException('Value exceeds max_output_bytes');
		}
		if ($mode === 'html' && !$htmlSanitised) {
			$value = FreshRSS_SimplePieCustom::sanitizeHTML($value, $baseUrl);
		}
		if (strlen($value) > $maxOutput) {
			throw new InvalidArgumentException('Sanitised value exceeds max_output_bytes');
		}
		$value = trim($value);
		foreach ($candidate['postprocess'] ?? [] as $transform) {
			if ($value === '') {
				break;
			}
			$value = self::transform($value, $transform, $baseUrl);
			if (strlen($value) > $maxOutput) {
				throw new InvalidArgumentException('Transformed value exceeds max_output_bytes');
			}
		}
		if ($includeItems) {
			$itemBytes = 0;
			foreach (($candidate['all'] ?? false) ? $parts : [$value] as $part) {
				$part = trim($part);
				if ($candidate['all'] ?? false) {
					if ($mode === 'html') {
						$part = FreshRSS_SimplePieCustom::sanitizeHTML($part, $baseUrl);
					}
					foreach ($candidate['postprocess'] ?? [] as $transform) {
						$part = $part === '' ? '' : self::transform($part, $transform, $baseUrl);
					}
				}
				if ($part !== '') {
					self::append($items, $part, $itemBytes, $maxOutput, '');
				}
			}
		}
		return $value;
	}

	/** @return list<string> */
	private static function validateTransforms(mixed $transforms, mixed $mode): array {
		if (!is_array($transforms) || !array_is_list($transforms) || count($transforms) > self::MAX_TRANSFORMS) {
			return ['postprocess must be a list of at most ' . self::MAX_TRANSFORMS . ' operations'];
		}
		if ($transforms !== [] && $mode === 'html') {
			return ['postprocess requires text or attribute mode'];
		}
		$errors = [];
		foreach ($transforms as $transform) {
			if (!is_array($transform) || !in_array($transform['type'] ?? null, ['trim', 'normalize_whitespace', 'absolute_url', 'date'], true)) {
				$errors[] = 'Unsupported postprocess operation';
				continue;
			}
			foreach (array_keys($transform) as $key) {
				if (!in_array($key, $transform['type'] === 'date' ? ['type', 'input_format', 'output_format', 'timezone'] : ['type'], true)) {
					$errors[] = 'Unknown postprocess option';
				}
			}
			if ($transform['type'] !== 'date') {
				continue;
			}
			if (!self::validString($transform['input_format'] ?? null)) {
				$errors[] = 'date requires a non-empty input_format';
			}
			foreach (['output_format', 'timezone'] as $key) {
				if (array_key_exists($key, $transform) && !self::validString($transform[$key])) {
					$errors[] = 'Invalid date ' . $key;
				}
			}
			if (is_string($transform['timezone'] ?? null)) {
				try {
					new DateTimeZone($transform['timezone']);
				} catch (Exception) {
					$errors[] = 'Unknown date timezone';
				}
			}
		}
		return $errors;
	}

	/** @param Transform $transform
	 * @throws InvalidArgumentException
	 */
	private static function transform(string $value, array $transform, string $baseUrl): string {
		switch ($transform['type']) {
			case 'trim':
				return trim($value);
			case 'normalize_whitespace':
				return trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value);
			case 'absolute_url':
				return self::resolveUrl($value, $baseUrl);
			case 'date':
				$date = DateTimeImmutable::createFromFormat('!' . ($transform['input_format'] ?? ''), $value,
					new DateTimeZone($transform['timezone'] ?? 'UTC'));
				$errors = DateTimeImmutable::getLastErrors();
				if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
					throw new InvalidArgumentException('Date does not match input_format');
				}
				return $date->format($transform['output_format'] ?? DateTimeInterface::ATOM);
			default:
				throw new InvalidArgumentException('Unsupported postprocess operation');
		}
	}

	/** Resolve an article link without any network access.
	 * @throws InvalidArgumentException
	 */
	public static function resolveUrl(string $value, string $baseUrl = ''): string {
		$value = trim($value);
		if (preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1 || preg_match('/[\x00-\x20\x7f\\\\]/', $baseUrl) === 1) {
			throw new InvalidArgumentException('URL contains whitespace, control characters or backslashes');
		}
		$url = \SimplePie\Misc::absolutize_url($value, $baseUrl);
		$scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;
		$host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
		if (!is_string($url) || !is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)
			|| !is_string($host) || $host === '') {
			throw new InvalidArgumentException('URL must resolve to an absolute HTTP or HTTPS URL');
		}
		return $url;
	}

	/** Keep dot-notation concatenation bounded before allocating its result.
	 * @throws InvalidArgumentException
	 */
	private static function jsonValue(mixed $data, string $selector, int $maxBytes): string {
		$selector = preg_replace_callback('/(?P<delim>[\'"])(?P<text>.*?)(?P=delim)/',
			static fn(array $match): string => str_replace('&', '＆', $match[0]), $selector) ?? $selector;
		$value = '';
		foreach (explode('&', $selector) as $part) {
			$next = FreshRSS_dotNotation_Util::getString($data, $part) ?? '';
			if (strlen($next) > $maxBytes - strlen($value)) {
				throw new InvalidArgumentException('Value exceeds max_output_bytes');
			}
			$value .= $next;
		}
		return $value;
	}

	/** @param list<string> $parts
	 * @throws InvalidArgumentException
	 */
	private static function append(array &$parts, string $value, int &$bytes, int $max, string $separator): void {
		$added = strlen($value) + ($parts === [] ? 0 : strlen($separator));
		if ($added > $max - $bytes) {
			throw new InvalidArgumentException('Value exceeds max_output_bytes');
		}
		$bytes += $added;
		$parts[] = $value;
	}

	/** @phpstan-assert-if-true string $value */
	private static function validString(mixed $value, bool $allowEmpty = false): bool {
		return is_string($value) && ($allowEmpty || $value !== '') && strlen($value) <= self::MAX_SELECTOR_BYTES
			&& !str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');
	}

	/** @throws InvalidArgumentException */
	private static function css(string $selector): string {
		return CssSelector::toXPath($selector);
	}

	/** @return DOMNodeList<DOMNode|DOMNameSpaceNode>
	 * @throws InvalidArgumentException
	 */
	private static function query(DOMXPath $xpath, string $expression): DOMNodeList {
		$nodes = @$xpath->evaluate($expression);
		if (!$nodes instanceof DOMNodeList) {
			throw new InvalidArgumentException('Invalid XPath or selector does not return nodes');
		}
		return $nodes;
	}

	/** @param list<string> $removeExpressions */
	private static function nodeValue(DOMNode $node, string $mode, array $removeExpressions): string {
		if (!$node instanceof DOMElement || ($mode === 'text' && $removeExpressions === [])) {
			return $mode === 'html' ? htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $node->textContent;
		}
		$doc = new DOMDocument();
		$copy = $doc->importNode($node, true);
		$doc->appendChild($copy);
		$xpath = new DOMXPath($doc);
		foreach ($removeExpressions as $expression) {
			foreach (self::query($xpath, $expression) as $unwanted) {
				if ($unwanted === $copy) {
					return '';
				}
				if ($unwanted instanceof DOMNode) {
					$unwanted->parentNode?->removeChild($unwanted);
				}
			}
		}
		return $mode === 'html' ? ($doc->saveHTML($copy) ?: '') : $copy->textContent;
	}

	/** @return list<array<array-key,mixed>>
	 * @throws InvalidArgumentException
	 */
	private static function jsonLd(DOMXPath $xpath, int $maxMatches): array {
		$nodes = self::query($xpath, '//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]');
		if ($nodes->length > $maxMatches) {
			throw new InvalidArgumentException('JSON-LD blocks exceed max_matches');
		}
		$result = [];
		$invalid = false;
		foreach ($nodes as $node) {
			if (!$node instanceof DOMElement) {
				continue;
			}
			try {
				$data = json_decode($node->textContent, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
			} catch (JsonException) {
				$invalid = true;
				continue;
			}
			foreach (self::jsonLdObjects($data, $maxMatches) as $object) {
				$result[] = $object;
				if (count($result) > $maxMatches) {
					throw new InvalidArgumentException('JSON-LD objects exceed max_matches');
				}
			}
		}
		if ($result === [] && $invalid) {
			throw new InvalidArgumentException('No valid JSON-LD block');
		}
		return $result;
	}

	/** @return list<array<array-key,mixed>> */
	private static function jsonLdObjects(mixed $data, int $maxMatches): array {
		if (!is_array($data)) {
			return [];
		}
		$objects = array_is_list($data) ? $data : [$data];
		$result = [];
		foreach ($objects as $object) {
			if (!is_array($object)) {
				continue;
			}
			$result[] = $object;
			if (isset($object['@graph']) && is_array($object['@graph'])) {
				foreach ($object['@graph'] as $child) {
					if (is_array($child)) {
						$result[] = $child;
						if (count($result) > $maxMatches) {
							throw new InvalidArgumentException('JSON-LD objects exceed max_matches');
						}
					}
				}
			}
			if (count($result) > $maxMatches) {
				throw new InvalidArgumentException('JSON-LD objects exceed max_matches');
			}
		}
		return $result;
	}
}
