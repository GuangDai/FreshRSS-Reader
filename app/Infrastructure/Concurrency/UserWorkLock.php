<?php
declare(strict_types=1);

namespace FreshRSS\Infrastructure\Concurrency;

use Minz_User;
use RuntimeException;

/** Serialises one user's ingestion mutations while allowing independent source downloads.
 * Reentrant in the current PHP process, including nested extension calls.
 */
final class UserWorkLock {
	/** @var array<string,array{handle:resource,count:int}> */
	private static array $held = [];
	private bool $released = false;

	private function __construct(private string $path) {
	}

	private static function path(): string {
		$user = Minz_User::name() ?: Minz_User::INTERNAL_USER;
		return TMP_PATH . '/apply.' . hash('sha256', (realpath(DATA_PATH) ?: DATA_PATH) . "\0" . $user) . '.freshrss.lock';
	}

	private static function open(bool $blocking): ?self {
		$path = self::path();
		if (isset(self::$held[$path])) {
			self::$held[$path]['count']++;
			return new self($path);
		}
		$handle = @fopen($path, 'c+');
		if ($handle === false) {
			throw new RuntimeException('Cannot open user work lock');
		}
		if (!flock($handle, LOCK_EX | ($blocking ? 0 : LOCK_NB))) {
			fclose($handle);
			if ($blocking) {
				throw new RuntimeException('Cannot acquire user work lock');
			}
			return null;
		}
		self::$held[$path] = ['handle' => $handle, 'count' => 1];
		return new self($path);
	}

	/** Queue workers have an independent kernel deadline covering this wait. */
	public static function acquire(): self {
		$lock = self::open(true);
		if ($lock === null) {
			throw new RuntimeException('Cannot acquire user work lock');
		}
		return $lock;
	}

	/** Interactive callers can return a retryable conflict without occupying a waiting Web worker. */
	public static function tryAcquire(): ?self {
		return self::open(false);
	}

	/** @template T
	 * @param callable():T $operation
	 * @return T
	 */
	public static function run(callable $operation): mixed {
		$lock = self::acquire();
		try {
			return $operation();
		} finally {
			$lock->release();
		}
	}

	public function release(): void {
		if ($this->released) {
			return;
		}
		$this->released = true;
		$held = self::$held[$this->path];
		if ($held['count'] === 1) {
			flock($held['handle'], LOCK_UN);
			fclose($held['handle']);
			unset(self::$held[$this->path]);
		} else {
			self::$held[$this->path] = ['handle' => $held['handle'], 'count' => $held['count'] - 1];
		}
	}

	public function __destruct() {
		$this->release();
	}
}
