<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Reader;

use DirectoryIterator;
use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Services\Reader\StorageService;
use FreshRSS_Auth;
use FreshRSS_DatabaseDAO;
use FreshRSS_EntryDAO;
use FreshRSS_FeedDAO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class StorageServiceTest extends TestCase {
	private string $directory;

	#[\Override]
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/freshrss-reader-storage-' . bin2hex(random_bytes(6));
		mkdir($this->directory);
		mkdir($this->directory . '/account');
	}

	#[\Override]
	protected function tearDown(): void {
		self::removeDirectory($this->directory);
	}

	private static function removeDirectory(string $directory): void {
		foreach (new DirectoryIterator($directory) as $file) {
			if ($file->isDot()) {
				continue;
			}
			if ($file->isDir() && !$file->isLink()) {
				self::removeDirectory($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}
		rmdir($directory);
	}

	private function database(): FreshRSS_DatabaseDAO&MockObject {
		$database = $this->createMock(FreshRSS_DatabaseDAO::class);
		$database->expects(self::never())->method('optimize');
		return $database;
	}

	/** @param array{all:int,read:int,unread:int,favorites:int} $states */
	private function service(FreshRSS_DatabaseDAO $database, string $type = 'sqlite',
		array $states = ['all' => 23, 'read' => 16, 'unread' => 7, 'favorites' => 4], int $feedCount = 3): StorageService {
		$entries = $this->createMock(FreshRSS_EntryDAO::class);
		$entries->expects(self::once())->method('countAsStates')->with(null)->willReturn($states);
		$entries->expects(self::never())->method('markRead');
		$entries->expects(self::never())->method('markFavorite');
		$feeds = $this->createMock(FreshRSS_FeedDAO::class);
		$feeds->expects(self::once())->method('count')->willReturn($feedCount);
		$feeds->expects(self::never())->method('purge');
		return new StorageService($database, $entries, $feeds,
			$this->directory . '/account', $type, 'private_database', 'prefix_user%_');
	}

	public function testCurrentAccountCountsIncludeAllPrioritiesAndDoNotExposeGlobalSize(): void {
		$database = $this->database();
		$database->expects(self::once())->method('size')->with(false)->willReturn(4096);
		$before = time();
		$summary = $this->service($database)->summary();
		self::assertSame(['type' => 'sqlite', 'bytes' => 4096], $summary['database']);
		self::assertSame(['total' => 23, 'read' => 16, 'unread' => 7, 'favourites' => 4], $summary['articles']);
		self::assertSame(3, $summary['feeds']);
		self::assertGreaterThanOrEqual($before, $summary['timestamp']);
		self::assertSame(['sqliteAvailable' => false, 'archives' => [], 'bytes' => 0], $summary['backups']);
	}

	public function testAdministratorScopeIsRequiredForGlobalDatabaseSize(): void {
		$database = $this->database();
		$database->expects(self::exactly(2))->method('size')->willReturnCallback(
			static fn(bool $all): int => $all ? 8192 : 4096,
		);
		$summary = $this->service($database, 'pgsql')->summary(true);
		self::assertSame(['type' => 'pgsql', 'bytes' => 4096, 'totalBytes' => 8192], $summary['database']);
	}

	public function testMysqlOnlyReadsMetadataAndEscapesTheCurrentAccountPrefix(): void {
		$database = $this->database();
		$database->expects(self::never())->method('size');
		$database->expects(self::exactly(2))->method('fetchInt')->willReturnCallback(
			static function (string $sql, array $values): int {
				self::assertStringStartsWith('SELECT SUM(DATA_LENGTH + INDEX_LENGTH + DATA_FREE)', $sql);
				self::assertStringNotContainsString('ANALYZE', $sql);
				self::assertSame('private_database', $values[':schema']);
				if (array_key_exists(':prefix', $values)) {
					self::assertStringContainsString("\nAND TABLE_NAME LIKE :prefix", $sql);
					self::assertSame('prefix\\_user\\%\\_%', $values[':prefix']);
					return 1024;
				}
				self::assertStringNotContainsString('TABLE_NAME', $sql);
				return 4096;
			},
		);
		$summary = $this->service($database, 'mysql')->summary(true);
		self::assertSame(['type' => 'mysql', 'bytes' => 1024, 'totalBytes' => 4096], $summary['database']);
		self::assertStringNotContainsString('private_database', json_encode($summary, JSON_THROW_ON_ERROR));
		self::assertStringNotContainsString('prefix', json_encode($summary, JSON_THROW_ON_ERROR));
	}

	public function testArchivesAreLimitedToSafeCurrentAccountFilesAndSortedNewestFirst(): void {
		$account = $this->directory . '/account';
		foreach (['db.sqlite' => 1800000000, 'old.sqlite' => 1700000000, 'new.sqlite' => 1750000000,
			'.hidden.sqlite' => 1800000001, 'bad!.sqlite' => 1800000002] as $name => $time) {
			file_put_contents($account . '/' . $name, '1234');
			touch($account . '/' . $name, $time);
		}
		file_put_contents($account . '/empty.sqlite', '');
		file_put_contents($this->directory . '/other-account.sqlite', 'private');
		symlink($this->directory . '/other-account.sqlite', $account . '/linked.sqlite');
		mkdir($account . '/folder.sqlite');
		$database = $this->database();
		$database->method('size')->willReturn(4);
		$summary = $this->service($database)->summary();
		self::assertSame([
			['name' => 'db.sqlite', 'bytes' => 4, 'modifiedAt' => 1800000000, 'active' => true],
			['name' => 'new.sqlite', 'bytes' => 4, 'modifiedAt' => 1750000000, 'active' => false],
			['name' => 'old.sqlite', 'bytes' => 4, 'modifiedAt' => 1700000000, 'active' => false],
		], $summary['backups']['archives']);
		self::assertTrue($summary['backups']['sqliteAvailable']);
		self::assertSame(8, $summary['backups']['bytes']);
		self::assertStringNotContainsString($this->directory, json_encode($summary, JSON_THROW_ON_ERROR));
	}

	public function testDbSqliteArchiveOnAnotherBackendIsNotMistakenForAnActiveDatabase(): void {
		file_put_contents($this->directory . '/account/db.sqlite', '1234');
		$database = $this->database();
		$database->method('size')->willReturn(4096);
		$summary = $this->service($database, 'pgsql')->summary();
		self::assertFalse($summary['backups']['archives'][0]['active']);
		self::assertSame(4, $summary['backups']['bytes']);
	}

	public function testFailedCountsRemainUnknownInsteadOfPretendingToBeAnEmptyAccount(): void {
		$database = $this->database();
		$database->method('size')->willReturn(-1);
		$summary = $this->service($database, states: ['all' => -1, 'read' => -1, 'unread' => -1, 'favorites' => -1], feedCount: -1)->summary();
		self::assertNull($summary['database']['bytes']);
		self::assertSame(['total' => null, 'read' => null, 'unread' => null, 'favourites' => null], $summary['articles']);
		self::assertNull($summary['feeds']);
	}

	public function testUnauthenticatedDescribeIsRejectedBeforeReadingAccountData(): void {
		$login = new ReflectionProperty(FreshRSS_Auth::class, 'login_ok');
		$previous = $login->getValue();
		$login->setValue(null, false);
		try {
			StorageService::describe();
			self::fail('Anonymous storage inspection must be rejected');
		} catch (ApiException $exception) {
			self::assertSame(403, $exception->status);
			self::assertSame('forbidden', $exception->getMessage());
		} finally {
			$login->setValue(null, $previous);
		}
	}
}
