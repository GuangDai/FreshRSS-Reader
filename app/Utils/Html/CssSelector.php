<?php
declare(strict_types=1);

namespace FreshRSS\Utils\Html;

use InvalidArgumentException;

/** Compile an explicitly supported CSS subset without discarding unrecognised input. */
final class CssSelector {
	public const MAX_SELECTOR_BYTES = 2048;
	public const MAX_NESTING = 8;
	private int $offset = 0;
	private int $depth = 0;

	private function __construct(private readonly string $source) {
	}

	/** @throws InvalidArgumentException */
	public static function toXPath(string $selector): string {
		if ($selector === '' || strlen($selector) > self::MAX_SELECTOR_BYTES || str_contains($selector, "\0")
			|| !mb_check_encoding($selector, 'UTF-8')) {
			throw new InvalidArgumentException('Invalid CSS selector encoding or size');
		}
		$parser = new self($selector);
		$result = $parser->selectorList('//', false);
		$parser->space();
		if ($parser->offset !== strlen($selector)) {
			throw new InvalidArgumentException('Unexpected CSS token');
		}
		return $result;
	}

	private function selectorList(string $prefix, bool $inHas): string {
		$parts = [$this->complex($prefix, $inHas)];
		while ($this->take(',')) {
			$parts[] = $this->complex($prefix, $inHas);
		}
		return implode(' | ', $parts);
	}

	private function complex(string $prefix, bool $inHas): string {
		$this->space();
		$axis = $prefix;
		if ($prefix === './/') {
			$combinator = $this->match('/\G[>+~]/');
			if ($combinator !== null) {
				$axis = '.' . self::axis($combinator);
				$this->space();
			}
		}
		$result = $axis . $this->compound($inHas);
		while (true) {
			$spaced = $this->space();
			$next = $this->source[$this->offset] ?? '';
			if ($next === '' || $next === ',' || $next === ')') {
				return $result;
			}
			$combinator = $this->match('/\G[>+~]/');
			if ($combinator === null && !$spaced) {
				throw new InvalidArgumentException('Expected a CSS combinator');
			}
			$this->space();
			$result .= ($combinator === null ? '//' : self::axis($combinator)) . $this->compound($inHas);
		}
	}

	private static function axis(string $combinator): string {
		return match ($combinator) {
			'>' => '/',
			'+' => '/following-sibling::*[1]/self::',
			'~' => '/following-sibling::',
			default => throw new InvalidArgumentException('Unsupported CSS combinator'),
		};
	}

	private function compound(bool $inHas): string {
		$tag = $this->take('*') ? '*' : $this->identifier();
		$conditions = [];
		while (true) {
			if ($this->take('#')) {
				$conditions[] = '@id=' . self::literal($this->requiredIdentifier());
			} elseif ($this->take('.')) {
				$conditions[] = "contains(concat(' ',normalize-space(@class),' ')," . self::literal(' ' . $this->requiredIdentifier() . ' ') . ')';
			} elseif ($this->take('[')) {
				$conditions[] = $this->attribute();
			} elseif ($this->take(':')) {
				$conditions[] = $this->pseudo($tag, $inHas);
			} else {
				break;
			}
		}
		if ($tag === null && $conditions === []) {
			throw new InvalidArgumentException('Expected a CSS compound selector');
		}
		return strtolower($tag ?? '*') . ($conditions === [] ? '' : '[' . implode(' and ', array_map(
			static fn(string $condition): string => '(' . $condition . ')', $conditions)) . ']');
	}

	private function attribute(): string {
		$this->space();
		$name = '@' . strtolower($this->requiredIdentifier());
		$this->space();
		if ($this->take(']')) {
			return $name;
		}
		$operator = $this->match('/\G[~|^$*]?=/');
		if ($operator === null) {
			throw new InvalidArgumentException('Unsupported CSS attribute operator');
		}
		$this->space();
		$value = $this->quoted() ?? $this->requiredIdentifier();
		$this->space();
		$this->expect(']');
		$literal = self::literal($value);
		if ($value === '' && in_array($operator, ['~=', '^=', '$=', '*='], true)) {
			return 'false()';
		}
		return match ($operator) {
			'=' => $name . '=' . $literal,
			'~=' => preg_match('/[\x20\t\r\n\f]/', $value) === 1 ? 'false()'
				: "contains(concat(' ',normalize-space(" . $name . "),' ')," . self::literal(' ' . $value . ' ') . ')',
			'|=' => $name . '=' . $literal . ' or starts-with(' . $name . ',' . self::literal($value . '-') . ')',
			'^=' => $name . ' and starts-with(' . $name . ',' . $literal . ')',
			'$=' => $name . ' and substring(' . $name . ',string-length(' . $name . ')-string-length(' . $literal . ')+1)=' . $literal,
			'*=' => $name . ' and contains(' . $name . ',' . $literal . ')',
			default => throw new InvalidArgumentException('Unsupported CSS attribute operator'),
		};
	}

	private function pseudo(?string $tag, bool $inHas): string {
		if (++$this->depth > self::MAX_NESTING) {
			throw new InvalidArgumentException('CSS nesting limit exceeded');
		}
		try {
			$name = $this->requiredIdentifier();
			if (in_array($name, ['first-child', 'last-child'], true)) {
				return 'not(' . ($name === 'first-child' ? 'preceding' : 'following') . '-sibling::*)';
			}
			if (in_array($name, ['first-of-type', 'last-of-type', 'nth-of-type'], true) && ($tag === null || $tag === '*')) {
				throw new InvalidArgumentException('CSS of-type selectors require an explicit element name');
			}
			if (in_array($name, ['first-of-type', 'last-of-type'], true)) {
				return 'not(' . ($name === 'first-of-type' ? 'preceding' : 'following') . '-sibling::' . strtolower($tag ?? '') . ')';
			}
			if (in_array($name, ['nth-child', 'nth-of-type'], true)) {
				$this->expect('(');
				$this->space();
				$number = $this->match('/\G[1-9][0-9]*/');
				if ($number === null) {
					throw new InvalidArgumentException('CSS nth selectors require a positive integer');
				}
				$this->space();
				$this->expect(')');
				return 'count(preceding-sibling::' . ($name === 'nth-child' ? '*' : strtolower($tag ?? '')) . ')=' . $number . '-1';
			}
			if (!in_array($name, ['not', 'has', 'contains'], true)) {
				throw new InvalidArgumentException('Unsupported CSS pseudo-class');
			}
			$this->expect('(');
			$this->space();
			if ($name === 'contains') {
				$value = $this->quoted();
				if ($value === null) {
					throw new InvalidArgumentException('CSS contains requires a quoted string');
				}
				$result = 'contains(.,' . self::literal($value) . ')';
			} elseif ($name === 'has') {
				if ($inHas) {
					throw new InvalidArgumentException('Nested CSS has selectors are not supported');
				}
				$result = $this->selectorList('.//', true);
			} else {
				$parts = [];
				do {
					$this->space();
					$parts[] = 'self::' . $this->compound($inHas);
					$this->space();
				} while ($this->take(','));
				$result = 'not(' . implode(' | ', $parts) . ')';
			}
			$this->space();
			$this->expect(')');
			return $result;
		} finally {
			$this->depth--;
		}
	}

	private static function literal(string $value): string {
		if (!str_contains($value, "'")) {
			return "'" . $value . "'";
		}
		return 'concat(\'' . implode('\',"\'",\'', explode("'", $value)) . '\')';
	}

	private function quoted(): ?string {
		$quote = $this->source[$this->offset] ?? '';
		if ($quote !== '"' && $quote !== "'") {
			return null;
		}
		$this->offset++;
		$end = strpos($this->source, $quote, $this->offset);
		if ($end === false) {
			throw new InvalidArgumentException('Unclosed CSS string');
		}
		$value = substr($this->source, $this->offset, $end - $this->offset);
		if (preg_match('/[\\\\\r\n\f]/', $value) === 1) {
			throw new InvalidArgumentException('Escaped or multiline CSS strings are not supported');
		}
		$this->offset = $end + 1;
		return $value;
	}

	private function identifier(): ?string {
		return $this->match('/\G-?[a-zA-Z_][a-zA-Z0-9_-]*/');
	}

	private function requiredIdentifier(): string {
		return $this->identifier() ?? throw new InvalidArgumentException('Expected an ASCII CSS identifier');
	}

	private function space(): bool {
		return $this->match('/\G[\x20\t\r\n\f]+/') !== null;
	}

	private function match(string $pattern): ?string {
		if (preg_match($pattern, $this->source, $matches, 0, $this->offset) !== 1) {
			return null;
		}
		$this->offset += strlen($matches[0]);
		return $matches[0];
	}

	private function take(string $token): bool {
		if (($this->source[$this->offset] ?? '') !== $token) {
			return false;
		}
		$this->offset++;
		return true;
	}

	private function expect(string $token): void {
		if (!$this->take($token)) {
			throw new InvalidArgumentException('Unexpected CSS token or missing delimiter');
		}
	}
}
