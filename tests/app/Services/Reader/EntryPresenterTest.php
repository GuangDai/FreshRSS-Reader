<?php
declare(strict_types=1);

namespace FreshRSS\Tests\App\Services\Reader;

use FreshRSS\Services\Reader\EntryPresenter;
use FreshRSS_Entry;
use PHPUnit\Framework\TestCase;

final class EntryPresenterTest extends TestCase {
	public function testEntryIdsRejectOverflowAndNonDecimalInput(): void {
		self::assertSame('1791856235002504', EntryPresenter::entryId('1791856235002504'));
		foreach (['0', '-1', '1e12', '1 OR 1=1', '9223372036854775808', '0001'] as $id) {
			self::assertNull(EntryPresenter::entryId($id));
		}
	}

	public function testSummaryIsPlainMetadataWithoutBody(): void {
		$entry = new FreshRSS_Entry(title: 'A &amp; B', content: '<p>Private body</p>', is_read: null, is_favorite: null);
		$summary = EntryPresenter::summary($entry);
		self::assertSame('A & B', $summary['title']);
		self::assertFalse($summary['read']);
		self::assertFalse($summary['starred']);
		self::assertArrayNotHasKey('content', $summary);
	}
}
