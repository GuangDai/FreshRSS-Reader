<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Reader;

use ArrayIterator;
use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Services\Reader\EntryStateService;
use FreshRSS_Entry;
use FreshRSS_EntryDAO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EntryStateServiceTest extends TestCase {
	public function testMissingBatchEntryPreventsEveryWrite(): void {
		$dao = $this->createMock(FreshRSS_EntryDAO::class);
		$dao->expects(self::once())->method('existingIds')->with(['1', '2'])->willReturn(['1']);
		$dao->expects(self::never())->method('markRead');
		$dao->expects(self::never())->method('markFavorite');
		$invalidations = 0;
		$service = new EntryStateService($dao, static function () use (&$invalidations): void { $invalidations++; });
		try {
			$service->batch(['ids' => ['1', '2'], 'read' => true], 2);
			self::fail('Missing entry must reject the entire batch');
		} catch (ApiException $exception) {
			self::assertSame(404, $exception->status);
			self::assertSame('not_found', $exception->getMessage());
		}
		self::assertSame(0, $invalidations);
	}

	public function testFailedReadIsNotMisreportedAsMissingData(): void {
		$dao = $this->createMock(FreshRSS_EntryDAO::class);
		$dao->method('existingIds')->willReturn(null);
		$dao->expects(self::never())->method('markRead');
		try {
			(new EntryStateService($dao))->batch(['ids' => ['1'], 'read' => true], 2);
			self::fail('Read failure expected');
		} catch (ApiException $exception) {
			self::assertSame(500, $exception->status);
			self::assertSame('read_failed', $exception->getMessage());
		}
	}

	public function testThrowingHookInvalidatesExactlyOnceAndDoesNotExposeItsMessage(): void {
		$dao = $this->createMock(FreshRSS_EntryDAO::class);
		$dao->method('existingIds')->willReturn(['1']);
		$dao->method('markFavorite')->willThrowException(new RuntimeException('Private extension credential'));
		$invalidations = 0;
		$service = new EntryStateService($dao, static function () use (&$invalidations): void { $invalidations++; });
		try {
			$service->batch(['ids' => ['1'], 'starred' => true], 1);
			self::fail('Hook failure expected');
		} catch (ApiException $exception) {
			self::assertSame('write_failed', $exception->getMessage());
			self::assertSame(['atomic' => false], $exception->details);
		}
		self::assertSame(1, $invalidations);
	}

	public function testFalseWriteAlsoInvalidatesAndReturnsStructuredFailure(): void {
		$dao = $this->createMock(FreshRSS_EntryDAO::class);
		$dao->method('existingIds')->willReturn(['1']);
		$dao->method('markRead')->willReturn(false);
		$invalidations = 0;
		$service = new EntryStateService($dao, static function () use (&$invalidations): void { $invalidations++; });
		try {
			$service->batch(['ids' => ['1'], 'read' => false], 1);
			self::fail('Database failure expected');
		} catch (ApiException $exception) {
			self::assertSame(500, $exception->status);
		}
		self::assertSame(1, $invalidations);
	}

	public function testSingleStatePreservesTheUnchangedFieldWithoutLoadingContent(): void {
		$entry = new FreshRSS_Entry(is_read: false, is_favorite: true);
		$entry->_id('9007199254740993');
		$dao = $this->createMock(FreshRSS_EntryDAO::class);
		$dao->expects(self::once())->method('listSummaries')->willReturn(new ArrayIterator([$entry]));
		$dao->expects(self::never())->method('searchById');
		$dao->expects(self::once())->method('markRead')->with(['9007199254740993'], true)->willReturn(1);
		$invalidations = 0;
		$service = new EntryStateService($dao, static function () use (&$invalidations): void { $invalidations++; });
		self::assertSame(['id' => '9007199254740993', 'read' => true, 'starred' => true],
			$service->state(['id' => '9007199254740993', 'read' => true]));
		self::assertSame(1, $invalidations);
	}

}
