<?php
declare(strict_types=1);

namespace FreshRSS\Exceptions\Reader;

use RuntimeException;
use Throwable;

/** Domain failure translated to JSON only at the reader HTTP boundary. */
final class ApiException extends RuntimeException {
	/** @param array<string,mixed> $details */
	public function __construct(string $error, public readonly int $status = 400, public readonly array $details = [], ?Throwable $previous = null) {
		parent::__construct($error, 0, $previous);
	}
}
