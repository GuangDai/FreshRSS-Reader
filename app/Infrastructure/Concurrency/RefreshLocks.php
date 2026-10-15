<?php
declare(strict_types=1);

namespace FreshRSS\Infrastructure\Concurrency;

use RuntimeException;
use Throwable;

/** Kernel-held execution slots survive loss of the supervisor and release on worker exit. */
final class RefreshLocks {
	/** @var list<resource> */
	private array $handles = [];

	public function __construct(private string $directory) {
	}

	public function acquire(string $user, string $host, int $workers, int $perHost, int $perUser = 1, bool $maintenance = false): bool {
		$this->release();
		try {
			$activity = @fopen($this->directory . '/activity-' . hash('sha256', $user) . '.lock', 'c+');
			if ($activity === false) {
				throw new RuntimeException('Cannot open user activity lock');
			}
			if (!flock($activity, ($maintenance ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
				fclose($activity);
				return false;
			}
			$this->handles[] = $activity;
			if (!$this->slot('user-' . hash('sha256', $user), $perUser) || !$this->slot('global', $workers) ||
				($host !== '' && !$this->slot('host-' . hash('sha256', strtolower($host)), $perHost))) {
				$this->release();
				return false;
			}
			return true;
		} catch (Throwable $e) {
			$this->release();
			throw $e;
		}
	}

	private function slot(string $name, int $count): bool {
		for ($slot = 0; $slot < $count; $slot++) {
			$handle = actualize_acquire_lock($this->directory . '/' . $name . '-' . $slot . '.lock');
			if ($handle !== false) {
				$this->handles[] = $handle;
				return true;
			}
		}
		return false;
	}

	public function release(): void {
		foreach ($this->handles as $handle) {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
		$this->handles = [];
	}

	public function __destruct() {
		$this->release();
	}
}
