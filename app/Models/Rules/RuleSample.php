<?php
declare(strict_types=1);

namespace FreshRSS\Models\Rules;

use Closure;
use FreshRSS\Exceptions\Rules\RuleException;
use FreshRSS\Services\Rules\RuleEngine;

/** @internal Validated input shared by compiled predicates.
 * @phpstan-import-type RuleReason from RuleEngine
 * @phpstan-import-type RuleLimits from RuleEngine
 */
final class RuleSample {
	/** @var array<string,list<string>> */
	private array $foldedFields = [];
	/** @var list<RuleReason> */
	private array $reasons = [];
	private bool $truncated = false;
	/** @var array<string,list<string>> */
	private array $fields = [];
	private int $bytes = 0;
	private int $valueCount = 0;
	private int $matchChecks = 0;

	/** @param array<array-key,mixed> $fields
	 * @param RuleLimits $limits
	 * @param ?Closure(string):(string|array<string>) $resolver
	 * @throws RuleException
	 */
	public function __construct(array $fields, private readonly array $limits, private readonly int $reasonLimit = 0,
		public readonly bool $updated = false, private readonly ?Closure $resolver = null) {
		foreach ($fields as $field => $values) {
			if (!is_string($field) || !in_array($field, RuleEngine::FIELDS, true)) {
				throw new RuleException('sample.' . $field . ': unknown field');
			}
			$this->normalise($field, $values);
		}
	}

	/** @throws RuleException */
	private function normalise(string $field, mixed $values): void {
		if (in_array($field, RuleEngine::NUMERIC_FIELDS, true)) {
			$integer = is_string($values) ? filter_var($values, FILTER_VALIDATE_INT) : false;
			if ($integer === false || (string)$integer !== $values || ($field === 'content_length' && $integer < 0)) {
				throw new RuleException('sample.' . $field . ': expected canonical integer text within PHP integer range');
			}
		}
		$values = is_array($values) ? $values : [$values];
		if (count($values) > $this->limits['sample_values'] - $this->valueCount) {
			throw new RuleException('sample.' . $field . ': total sample_values limit exceeded (' . $this->limits['sample_values'] . ')');
		}
		$this->valueCount += count($values);
		if (!array_is_list($values)) {
			throw new RuleException('sample.' . $field . ': expected text or list of text');
		}
		$this->fields[$field] = [];
		foreach ($values as $value) {
			if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
				throw new RuleException('sample.' . $field . ': expected UTF-8 text or list of UTF-8 text');
			}
			if (strlen($value) > $this->limits['text_bytes'] - $this->bytes) {
				throw new RuleException('sample: total text_bytes limit exceeded');
			}
			$this->bytes += strlen($value);
			$this->fields[$field][] = $value;
		}
	}

	/** @return list<string>
	 * @throws RuleException
	 */
	public function values(string $field, bool $caseSensitive): array {
		if ($field === 'content_length' && !isset($this->fields[$field])) {
			return $this->foldedFields[$field] ??= [(string)array_sum(array_map(
				static fn(string $value): int => mb_strlen($value, 'UTF-8'), $this->values('content', true)))];
		}
		if (!isset($this->fields[$field]) && $this->resolver !== null) {
			$this->normalise($field, ($this->resolver)($field));
		}
		if ($caseSensitive) {
			return $this->fields[$field] ?? [];
		}
		return $this->foldedFields[$field] ??= array_map(
			static fn(string $value): string => mb_strtolower($value, 'UTF-8'), $this->fields[$field] ?? []);
	}

	/** Reserve a conservative comparison budget, independent of explanation and text short-circuiting.
	 * @throws RuleException
	 */
	public function check(int $count = 1): void {
		if ($count > $this->limits['match_checks'] - $this->matchChecks) {
			throw new RuleException('sample: match_checks limit exceeded (' . $this->limits['match_checks'] . ')');
		}
		$this->matchChecks += $count;
	}

	public function needsReason(): bool {
		return $this->reasonLimit > count($this->reasons);
	}

	public function record(string $path, string $op, bool $matched, ?string $field = null, ?int $actual = null): bool {
		if ($this->reasonLimit === 0) {
			return $matched;
		}
		if (count($this->reasons) >= $this->reasonLimit) {
			$this->truncated = true;
			return $matched;
		}
		$reason = ['path' => $path, 'op' => $op, 'matched' => $matched];
		if ($field !== null) {
			$reason['field'] = $field;
		}
		if ($actual !== null) {
			$reason['actual'] = $actual;
		}
		$this->reasons[] = $reason;
		return $matched;
	}

	/** @return array{conditions:list<RuleReason>,truncated:bool} */
	public function explanation(): array {
		return ['conditions' => $this->reasons, 'truncated' => $this->truncated];
	}
}
