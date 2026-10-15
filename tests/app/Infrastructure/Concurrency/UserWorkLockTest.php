<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Infrastructure\Concurrency;

use FreshRSS\Infrastructure\Concurrency\UserWorkLock;
use Minz_User;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class UserWorkLockTest extends TestCase {
	public function testNestedCallsKeepTheLockUntilTheLastOwnerReleases(): void {
		$previous = Minz_User::name();
		Minz_User::change('locktest' . bin2hex(random_bytes(5)));
		$first = UserWorkLock::acquire();
		$second = UserWorkLock::tryAcquire();
		self::assertNotNull($second);
		$path = (new ReflectionProperty(UserWorkLock::class, 'path'))->getValue($first);
		self::assertIsString($path);
		$outside = fopen($path, 'c+');
		self::assertIsResource($outside);
		try {
			self::assertFalse(flock($outside, LOCK_EX | LOCK_NB));
			$first->release();
			self::assertFalse(flock($outside, LOCK_EX | LOCK_NB));
			$second->release();
			self::assertTrue(flock($outside, LOCK_EX | LOCK_NB));
		} finally {
			$first->release();
			$second->release();
			fclose($outside);
			unlink($path);
			Minz_User::change($previous ?? '');
		}
	}

	public function testNonBlockingTryDoesNotWaitForAnUnrelatedOwner(): void {
		$previous = Minz_User::name();
		Minz_User::change('locktest' . bin2hex(random_bytes(5)));
		$probe = UserWorkLock::acquire();
		$path = (new ReflectionProperty(UserWorkLock::class, 'path'))->getValue($probe);
		self::assertIsString($path);
		$probe->release();
		$outside = fopen($path, 'c+');
		self::assertIsResource($outside);
		try {
			self::assertTrue(flock($outside, LOCK_EX));
			self::assertNull(UserWorkLock::tryAcquire());
		} finally {
			fclose($outside);
			unlink($path);
			Minz_User::change($previous ?? '');
		}
	}
}
