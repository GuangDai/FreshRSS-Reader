<?php
declare(strict_types=1);

namespace FreshRSS\Services\Rules;

use FreshRSS\Exceptions\Rules\RuleException;
use JsonException;
use LogicException;
use stdClass;

/** Atomic in-memory editing of version 1 rule documents. Persistence and optimistic locking belong to the settings service. */
final class RuleDocument {
	/** @param array<array-key,mixed> $operations
	 * @throws RuleException
	 */
	public static function patch(string $json, array $operations, int $maxOperations, int $maxJsonBytes): string {
		RuleEngine::fromJson($json, $maxJsonBytes);
		if ($maxOperations < 1 || !array_is_list($operations) || count($operations) > $maxOperations) {
			throw new RuleException('operations: expected list with at most ' . $maxOperations . ' operations');
		}
		try {
			$encodedOperations = json_encode($operations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, RuleEngine::JSON_DEPTH);
			if (strlen($encodedOperations) > $maxJsonBytes) {
				throw new RuleException('operations: encoded input exceeds maxJsonBytes (' . $maxJsonBytes . ')');
			}
			$data = json_decode($json, false, RuleEngine::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new RuleException('operations: expected finite JSON data: ' . $exception->getMessage(), 0, $exception);
		}
		if (!$data instanceof stdClass || !isset($data->rules) || !is_array($data->rules)) {
			throw new LogicException('Validated rule document lost its rules list');
		}
		$rules = array_values($data->rules);
		foreach ($operations as $index => $operation) {
			$path = 'operations[' . $index . ']';
			if (!is_array($operation) || array_is_list($operation) || !is_string($operation['op'] ?? null)) {
				throw new RuleException($path . ': expected operation object with op');
			}
			$allowed = match ($operation['op']) {
				'upsert' => ['op', 'rule'],
				'remove' => ['op', 'id'],
				'enable' => ['op', 'id', 'enabled'],
				'move' => ['op', 'id', 'before'],
				default => throw new RuleException($path . '.op: expected upsert, remove, enable or move'),
			};
			foreach (array_keys($operation) as $key) {
				if (!in_array($key, $allowed, true)) {
					throw new RuleException($path . '.' . $key . ': unknown operation property');
				}
			}
			if ($operation['op'] === 'upsert') {
				$rule = $operation['rule'] ?? null;
				if (!is_array($rule) || !is_string($rule['id'] ?? null) || trim($rule['id']) === '') {
					throw new RuleException($path . '.rule: expected complete rule object with non-empty id');
				}
				$position = self::position($rules, $rule['id']);
				$rule = (object)$rule;
				if ($position === null) {
					$rules[] = $rule;
				} else {
					$rules[$position] = $rule;
				}
				continue;
			}
			$id = $operation['id'] ?? null;
			if (!is_string($id) || $id === '') {
				throw new RuleException($path . '.id: expected non-empty rule ID');
			}
			$position = self::position($rules, $id);
			if ($position === null) {
				throw new RuleException($path . '.id: rule does not exist');
			}
			if ($operation['op'] === 'remove') {
				array_splice($rules, $position, 1);
			} elseif ($operation['op'] === 'enable') {
				if (!is_bool($operation['enabled'] ?? null)) {
					throw new RuleException($path . '.enabled: expected boolean');
				}
				$rule = $rules[$position];
				if (!$rule instanceof stdClass) {
					throw new LogicException('Rule selected for enable is not an object');
				}
				$rule->enabled = $operation['enabled'];
				$rules[$position] = $rule;
			} else {
				$before = $operation['before'] ?? null;
				if ($before !== null && (!is_string($before) || $before === '')) {
					throw new RuleException($path . '.before: expected rule ID or null for end');
				}
				if ($before === $id) {
					continue;
				}
				$rule = array_splice($rules, $position, 1)[0];
				$target = $before === null ? count($rules) : self::position($rules, $before);
				if ($target === null) {
					throw new RuleException($path . '.before: rule does not exist');
				}
				array_splice($rules, $target, 0, [$rule]);
			}
		}
		$data->rules = array_values($rules);
		try {
			$result = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
				RuleEngine::JSON_DEPTH);
		} catch (JsonException $exception) {
			throw new RuleException('operations: invalid rule data: ' . $exception->getMessage(), 0, $exception);
		}
		RuleEngine::fromJson($result, $maxJsonBytes);
		return $result;
	}

	/** @param array<int,mixed> $rules */
	private static function position(array $rules, string $id): ?int {
		foreach ($rules as $position => $rule) {
			if ($rule instanceof stdClass && ($rule->id ?? null) === $id) {
				return $position;
			}
		}
		return null;
	}
}
