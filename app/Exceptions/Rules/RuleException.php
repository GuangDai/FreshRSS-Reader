<?php
declare(strict_types=1);

namespace FreshRSS\Exceptions\Rules;

use InvalidArgumentException;

/** Validation and evaluation failures that callers can isolate from extension hook errors. */
final class RuleException extends InvalidArgumentException {
}
