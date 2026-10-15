<?php
declare(strict_types=1);

namespace FreshRSS\Services\Rules;

use Closure;
use FreshRSS\Exceptions\Rules\RuleException;
use FreshRSS\Models\Rules\RuleSample;
use FreshRSS_Entry;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Minz_ExtensionManager;
use Minz_HookType;
use stdClass;

/**
 * Declarative, literal text rules. Predicates are compiled once and never execute user code.
 * @phpstan-type RuleLimits array{rules:int,depth:int,nodes:int,text_bytes:int,sample_values:int,
 *   explanation_nodes:int,tag_bytes:int,scope_items:int,match_checks:int}
 * @phpstan-type RuleActions array{read?:bool,star?:bool,add_tags?:list<string>}
 * @phpstan-type RuleReason array{path:string,op:string,matched:bool,field?:string,actual?:int}
 * @phpstan-type RuleExplanation array{conditions:list<RuleReason>,truncated:bool,suppressed_actions?:list<string>}
 * @phpstan-type RuleResult array{matched:list<string>,actions:RuleActions,explanation?:RuleExplanation}
 */
final class RuleEngine {
	public const DEFAULT_PATCH_OPERATIONS = 1000;
	public const DEFAULT_JSON_BYTES = 1048576;
	public const JSON_DEPTH = 512;
	public const FIELDS = ['title', 'author', 'content', 'url', 'tags', 'feed_url', 'feed_id', 'content_length', 'published_at'];
	public const NUMERIC_FIELDS = ['content_length', 'published_at'];
	public const OPERATORS = ['contains', 'equals', 'starts_with', 'ends_with', 'empty', 'gt', 'gte', 'lt', 'lte'];
	public const DEFAULT_LIMITS = ['rules' => 100, 'depth' => 16, 'nodes' => 1000, 'text_bytes' => 4194304, 'sample_values' => 1024, 'explanation_nodes' => 100,
		'tag_bytes' => FreshRSS_Entry::MAX_TAGS_LENGTH, 'scope_items' => 1024, 'match_checks' => 100000];
	/** @var RuleLimits */
	private array $limits = self::DEFAULT_LIMITS;
	/** @var list<array{id:string,when:Closure(RuleSample):bool,actions:RuleActions,stop:bool}> */
	private array $rules = [];
	private int $nodes = 0;
	private int $scopeItems = 0;

	private function __construct() {
	}

	/** @throws RuleException */
	public static function fromJson(string $json, int $maxJsonBytes = self::DEFAULT_JSON_BYTES): self {
		if ($maxJsonBytes < 1 || strlen($json) > $maxJsonBytes) {
			throw new RuleException('rules: JSON exceeds maxJsonBytes (' . $maxJsonBytes . ')');
		}
		try {
			$data = json_decode($json, false, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuleException('rules: invalid JSON: ' . $e->getMessage(), 0, $e);
		}
		self::object($data, ['version', 'limits', 'rules'], 'rules');
		if (($data->version ?? null) !== 1) {
			throw new RuleException('rules.version: expected integer 1');
		}
		$engine = new self();
		if (property_exists($data, 'limits')) {
			self::object($data->limits, array_keys(self::DEFAULT_LIMITS), 'rules.limits');
			foreach (self::DEFAULT_LIMITS as $key => $default) {
				$value = property_exists($data->limits, $key) ? $data->limits->$key : $default;
				if (!is_int($value) || $value < 1) {
					throw new RuleException('rules.limits.' . $key . ': expected positive integer');
				}
				if ($key === 'tag_bytes' && $value > FreshRSS_Entry::MAX_TAGS_LENGTH) {
					throw new RuleException('rules.limits.tag_bytes: exceeds RSS category storage capacity (' . FreshRSS_Entry::MAX_TAGS_LENGTH . ')');
				}
				$engine->limits[$key] = $value;
			}
		}
		if (!isset($data->rules) || !is_array($data->rules) || count($data->rules) > $engine->limits['rules']) {
			throw new RuleException('rules.rules: expected list with at most ' . $engine->limits['rules'] . ' rules');
		}
		$ids = [];
		foreach ($data->rules as $index => $rule) {
			$path = 'rules.rules[' . $index . ']';
			self::object($rule, ['id', 'enabled', 'when', 'actions', 'stop', 'scope'], $path);
			$id = $rule->id ?? null;
			if (!is_string($id) || trim($id) === '' || isset($ids[$id])) {
				throw new RuleException($path . '.id: expected unique non-empty string');
			}
			$ids[$id] = true;
			foreach (['enabled', 'stop'] as $key) {
				if (property_exists($rule, $key) && !is_bool($rule->$key)) {
					throw new RuleException($path . '.' . $key . ': expected boolean');
				}
			}
			$when = $engine->predicate($rule->when ?? null, $path . '.when', 1);
			$actions = $engine->actions($rule->actions ?? null, $path . '.actions');
			if (property_exists($rule, 'scope')) {
				$scope = $engine->scope($rule->scope, $path . '.scope');
				$condition = $when;
				$when = static fn(RuleSample $sample): bool => $scope($sample) && $condition($sample);
			}
			if ($rule->enabled ?? true) {
				$engine->rules[] = ['id' => $id, 'when' => $when, 'actions' => $actions, 'stop' => ($rule->stop ?? false) === true];
			}
		}
		return $engine;
	}

	/** Apply a complete batch in memory, validating the final document before returning it.
	 * @param array<array-key,mixed> $operations
	 * @throws RuleException
	 */
	public static function patch(string $json, array $operations, int $maxOperations = self::DEFAULT_PATCH_OPERATIONS,
		int $maxJsonBytes = self::DEFAULT_JSON_BYTES): string {
		return RuleDocument::patch($json, $operations, $maxOperations, $maxJsonBytes);
	}

	/** @return list<string> */
	public static function validate(string $json, int $maxJsonBytes = self::DEFAULT_JSON_BYTES): array {
		try {
			self::fromJson($json, $maxJsonBytes);
			return [];
		} catch (InvalidArgumentException $e) {
			return [$e->getMessage()];
		}
	}

	/** @param list<string> $keys
	 * @phpstan-assert stdClass $value
	 */
	private static function object(mixed $value, array $keys, string $path): void {
		if (!$value instanceof stdClass) {
			throw new RuleException($path . ': expected JSON object');
		}
		foreach (get_object_vars($value) as $key => $_) {
			if (!in_array($key, $keys, true)) {
				throw new RuleException($path . '.' . $key . ': unknown property');
			}
		}
	}

	/** @return Closure(RuleSample):bool */
	private function scope(mixed $scope, string $path): Closure {
		self::object($scope, ['feed_ids', 'exclude_feed_ids', 'states'], $path);
		$sets = [];
		foreach (['feed_ids', 'exclude_feed_ids', 'states'] as $key) {
			if (!property_exists($scope, $key)) {
				continue;
			}
			$values = $scope->$key;
			if (!is_array($values) || $values === []) {
				throw new RuleException($path . '.' . $key . ': expected non-empty list');
			}
			$this->scopeItems += count($values);
			if ($this->scopeItems > $this->limits['scope_items']) {
				throw new RuleException($path . ': total scope_items limit exceeded (' . $this->limits['scope_items'] . ')');
			}
			$sets[$key] = [];
			foreach ($values as $value) {
				if ($key === 'states') {
					if (!is_string($value) || !in_array($value, ['new', 'updated'], true)) {
						throw new RuleException($path . '.states: expected new or updated');
					}
				} elseif (!is_int($value) || $value < 1) {
					throw new RuleException($path . '.' . $key . ': expected positive integer feed IDs');
				}
				$sets[$key][$value] = true;
			}
		}
		return static function (RuleSample $sample) use ($sets, $path): bool {
			$sample->check();
			if (isset($sets['states']) && !isset($sets['states'][$sample->updated ? 'updated' : 'new'])) {
				return $sample->record($path, 'scope', false);
			}
			if (isset($sets['feed_ids']) || isset($sets['exclude_feed_ids'])) {
				$values = $sample->values('feed_id', true);
				if ($values === []) {
					return $sample->record($path, 'scope', false, 'feed_id');
				}
				$feedId = filter_var($values[0], FILTER_VALIDATE_INT);
				if (count($values) !== 1 || $feedId === false || (string)$feedId !== $values[0] || $feedId < 0) {
					throw new RuleException('sample.feed_id: feed scope requires one canonical non-negative integer text');
				}
				if ((isset($sets['feed_ids']) && !isset($sets['feed_ids'][$feedId])) || isset($sets['exclude_feed_ids'][$feedId])) {
					return $sample->record($path, 'scope', false, 'feed_id');
				}
			}
			return $sample->record($path, 'scope', true);
		};
	}

	/** @return Closure(RuleSample):bool */
	private function predicate(mixed $node, string $path, int $depth): Closure {
		if ($depth > $this->limits['depth'] || ++$this->nodes > $this->limits['nodes']) {
			throw new RuleException($path . ': condition depth or total node limit exceeded');
		}
		self::object($node, ['all', 'any', 'not', 'field', 'op', 'value', 'case_sensitive', 'min_matches'], $path);
		foreach (['all', 'any', 'not'] as $group) {
			if (!property_exists($node, $group)) {
				continue;
			}
			if (count(get_object_vars($node)) !== 1) {
				throw new RuleException($path . ': a group must have exactly one property');
			}
			if ($group === 'not') {
				$child = $this->predicate($node->not, $path . '.not', $depth + 1);
				return static fn(RuleSample $sample): bool => $sample->record($path, 'not', !$child($sample));
			}
			$children = $node->$group;
			if (!is_array($children) || $children === []) {
				throw new RuleException($path . '.' . $group . ': expected non-empty list');
			}
			$predicates = [];
			foreach ($children as $index => $child) {
				$predicates[] = $this->predicate($child, $path . '.' . $group . '[' . $index . ']', $depth + 1);
			}
			return static function (RuleSample $sample) use ($predicates, $group, $path): bool {
				foreach ($predicates as $predicate) {
					if ($predicate($sample) !== ($group === 'all')) {
						return $sample->record($path, $group, $group === 'any');
					}
				}
				return $sample->record($path, $group, $group === 'all');
			};
		}
		$field = $node->field ?? null;
		$op = $node->op ?? null;
		$value = $node->value ?? '';
		$sensitive = property_exists($node, 'case_sensitive') ? $node->case_sensitive : false;
		if (!is_string($field) || !in_array($field, self::FIELDS, true)) {
			throw new RuleException($path . '.field: expected one of ' . implode(', ', self::FIELDS));
		}
		if (!is_string($op) || !in_array($op, self::OPERATORS, true)) {
			throw new RuleException($path . '.op: expected one of ' . implode(', ', self::OPERATORS));
		}
		if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
			if (!in_array($field, self::NUMERIC_FIELDS, true) || !is_int($value) ||
				($field === 'content_length' && $value < 0) || property_exists($node, 'case_sensitive') || property_exists($node, 'min_matches')) {
				throw new RuleException($path . ': numeric comparisons require a numeric field and integer value, without text options');
			}
			return static function (RuleSample $sample) use ($field, $op, $value, $path): bool {
				$sample->check();
				$text = $sample->values($field, true)[0] ?? null;
				if ($text === null) {
					return $sample->record($path, $op, false, $field);
				}
				$actual = (int)$text; // Canonical integer strings are checked before evaluation.
				$matched = match ($op) {
					'gt' => $actual > $value,
					'gte' => $actual >= $value,
					'lt' => $actual < $value,
					'lte' => $actual <= $value,
				};
				return $sample->record($path, $op, $matched, $field, $actual);
			};
		}
		$minimum = property_exists($node, 'min_matches') ? $node->min_matches : 1;
		if (!is_int($minimum) || $minimum < 1 || (property_exists($node, 'min_matches') && $op !== 'contains')) {
			throw new RuleException($path . '.min_matches: expected positive integer, supported only by contains');
		}
		if (!is_bool($sensitive) || !is_string($value) || strlen($value) > $this->limits['text_bytes'] ||
			($op !== 'empty' && $value === '') || ($op === 'empty' && property_exists($node, 'value'))) {
			throw new RuleException($path . ': invalid value or case_sensitive; empty takes no value, other operators need non-empty text');
		}
		$needle = $sensitive ? $value : mb_strtolower($value, 'UTF-8');
		return static function (RuleSample $sample) use ($field, $op, $needle, $sensitive, $minimum, $path): bool {
			$values = $sample->values($field, $sensitive || $op === 'empty');
			$sample->check(max(1, count($values)));
			if ($op === 'empty') {
				return $sample->record($path, $op, $values === [] || implode('', $values) === '', $field);
			}
			if ($op === 'contains') {
				$count = 0;
				foreach ($values as $text) {
					if ($minimum === 1 && !$sample->needsReason()) {
						if (str_contains($text, $needle)) {
							return $sample->record($path, $op, true, $field);
						}
						continue;
					}
					$count += substr_count($text, $needle);
					if ($count >= $minimum && !$sample->needsReason()) {
						return $sample->record($path, $op, true, $field);
					}
				}
				return $sample->record($path, $op, $count >= $minimum, $field, $count);
			}
			foreach ($values as $text) {
				$matches = match ($op) {
					'equals' => $text === $needle,
					'starts_with' => str_starts_with($text, $needle),
					'ends_with' => str_ends_with($text, $needle),
				};
				if ($matches) {
					return $sample->record($path, $op, true, $field);
				}
			}
			return $sample->record($path, $op, false, $field);
		};
	}

	/** @return RuleActions */
	private function actions(mixed $value, string $path): array {
		self::object($value, ['read', 'star', 'add_tags'], $path);
		$result = [];
		foreach (['read', 'star'] as $key) {
			if (property_exists($value, $key)) {
				if ($value->$key !== true) {
					throw new RuleException($path . '.' . $key . ': expected true');
				}
				$result[$key] = true;
			}
		}
		if (property_exists($value, 'add_tags')) {
			if (!is_array($value->add_tags) || $value->add_tags === []) {
				throw new RuleException($path . '.add_tags: expected non-empty list of text tags');
			}
			$tags = [];
			foreach ($value->add_tags as $tag) {
				if (!is_string($tag) || trim($tag) === '' || strlen($tag) > $this->limits['text_bytes']) {
					throw new RuleException($path . '.add_tags: expected non-empty text tags within text_bytes');
				}
				if (strpbrk($tag, '#,') !== false) {
					throw new RuleException($path . '.add_tags: tags cannot contain # or , (RSS category storage separators)');
				}
				$tags[] = trim($tag);
			}
			$result['add_tags'] = array_values(array_unique($tags));
			$this->mergeTags([], $result['add_tags'], $path . '.add_tags');
		}
		if ($result === []) {
			throw new RuleException($path . ': expected at least one action');
		}
		return $result;
	}

	/** Pure preview: no database writes or entry mutation. All rules see the original sample.
	 * @param array<array-key,mixed> $sample Plain text, already HTML-decoded.
	 * @return RuleResult
	 * @throws RuleException
	 */
	public function preview(array $sample, bool $explain = false, bool $updated = false): array {
		$input = new RuleSample($sample, $this->limits, $explain ? $this->limits['explanation_nodes'] : 0, $updated);
		return $this->evaluate($input, $explain);
	}

	/** @return RuleResult
	 * @throws RuleException
	 */
	private function evaluate(RuleSample $input, bool $explain): array {
		$result = ['matched' => [], 'actions' => []];
		foreach ($this->rules as $rule) {
			if (!($rule['when'])($input)) {
				continue;
			}
			$result['matched'][] = $rule['id'];
			foreach (['read', 'star'] as $action) {
				if ($rule['actions'][$action] ?? false) {
					$result['actions'][$action] = true;
				}
			}
			if (isset($rule['actions']['add_tags'])) {
				$result['actions']['add_tags'] = array_values(array_unique(array_merge(
					$result['actions']['add_tags'] ?? [], $rule['actions']['add_tags'])));
			}
			if ($rule['stop']) {
				break;
			}
		}
		$suppressed = [];
		if ($input->updated) {
			foreach (['star', 'add_tags'] as $action) {
				if (isset($result['actions'][$action])) {
					$suppressed[] = $action;
					unset($result['actions'][$action]);
				}
			}
		}
		if (isset($result['actions']['add_tags'])) {
			$this->mergeTags(self::encodeTags($input->values('tags', true)), $result['actions']['add_tags']);
		}
		if ($explain) {
			$result['explanation'] = $input->explanation();
			if ($suppressed !== []) {
				$result['explanation']['suppressed_actions'] = $suppressed;
			}
		}
		return $result;
	}

	/** @return RuleResult
	 * @throws RuleException
	 */
	public function previewEntry(FreshRSS_Entry $entry, bool $explain = false): array {
		if ($this->rules === []) {
			return $this->preview([], $explain);
		}
		$decode = static fn(string $text): string => html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$resolver = static fn(string $field): string|array => match ($field) {
			'title' => $decode($entry->title()),
			'author' => array_values(array_map($decode, $entry->authors())),
			'content' => $decode($entry->content(false)),
			'url' => html_entity_decode($entry->link(true), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'tags' => array_values(array_map($decode, $entry->tags())),
			'feed_id' => (string)$entry->feedId(),
			'feed_url' => $entry->feed()?->url(false) ?? '',
			'published_at' => (string)$entry->date(true),
			default => throw new LogicException('Unsupported rule source field: ' . $field),
		};
		$input = new RuleSample([], $this->limits, $explain ? $this->limits['explanation_nodes'] : 0,
			$entry->isUpdated() === true, $resolver);
		$result = $this->evaluate($input, $explain);
		if (isset($result['actions']['add_tags'])) {
			// Check the exact existing encoded representation before any state mutation.
			$this->mergeTags($entry->tags(), $result['actions']['add_tags']);
		}
		return $result;
	}

	/** @param array<string> $tags
	 * @return list<string>
	 */
	private static function encodeTags(array $tags): array {
		return array_values(array_map(static fn(string $tag): string =>
			htmlspecialchars($tag, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), $tags));
	}

	/** @param array<string> $existing Encoded RSS categories.
	 * @param list<string> $added Plain text category names.
	 * @return list<string>
	 * @throws RuleException
	 */
	private function mergeTags(array $existing, array $added, string $path = 'actions.add_tags'): array {
		$merged = array_values(array_unique(array_merge($existing, self::encodeTags($added))));
		$bytes = strlen('#' . implode(' #', $merged));
		if ($bytes > $this->limits['tag_bytes']) {
			throw new RuleException($path . ': encoded RSS categories exceed tag_bytes (' . $this->limits['tag_bytes'] . ')');
		}
		return $merged;
	}

	/** @throws RuleException */
	public function apply(FreshRSS_Entry $entry): FreshRSS_Entry {
		$actions = $this->previewEntry($entry)['actions'];
		$tags = isset($actions['add_tags']) ? $this->mergeTags($entry->tags(), $actions['add_tags']) : null;
		if (($actions['read'] ?? false) && !$entry->isRead()) {
			$entry->_isRead(true);
			Minz_ExtensionManager::callHook(Minz_HookType::EntryAutoRead, $entry, 'rule_engine');
		}
		if ($actions['star'] ?? false) {
			$entry->_isFavorite(true);
		}
		if ($tags !== null) {
			// Rule categories must not make an unchanged source appear updated at the next fetch.
			$sourceHash = $entry->hash();
			$entry->_tags($tags);
			$entry->_hash($sourceHash);
		}
		return $entry;
	}

	/** @return array<string,string> Ready-to-edit JSON configurations. */
	public static function presets(): array {
		$conditions = [
			'sponsored' => ['any' => [['field' => 'title', 'op' => 'contains', 'value' => 'sponsored'],
				['field' => 'tags', 'op' => 'equals', 'value' => 'advertisement']]],
			'releases' => ['all' => [['field' => 'title', 'op' => 'contains', 'value' => 'release'],
				['not' => ['field' => 'title', 'op' => 'contains', 'value' => 'prerelease']]]],
			'empty_content' => ['field' => 'content', 'op' => 'empty'],
			'short_content' => ['field' => 'content_length', 'op' => 'lt', 'value' => 200],
			'repeated_promotion' => ['field' => 'content', 'op' => 'contains', 'value' => 'subscribe', 'min_matches' => 3],
			'author_watch' => ['field' => 'author', 'op' => 'equals', 'value' => 'Example Author'],
			'feed_watch' => ['field' => 'feed_url', 'op' => 'starts_with', 'value' => 'https://example.org/'],
		];
		$result = [];
		foreach ($conditions as $id => $when) {
			$actions = in_array($id, ['sponsored', 'repeated_promotion'], true) ? ['read' => true] : ['star' => true, 'add_tags' => [$id]];
			$result[$id] = json_encode(['version' => 1, 'limits' => self::DEFAULT_LIMITS,
				'rules' => [['id' => $id, 'when' => $when, 'actions' => $actions]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		}
		return $result;
	}
}
