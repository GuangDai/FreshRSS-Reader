<?php
declare(strict_types=1);

final class CategoryDAOIntegrityTest extends \PHPUnit\Framework\TestCase {
	private Minz_PdoSqlite $pdo;
	private FreshRSS_CategoryDAOSQLite $dao;

	#[\Override]
	protected function setUp(): void {
		$this->pdo = new Minz_PdoSqlite('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
		$this->pdo->exec('CREATE TABLE category (id INTEGER PRIMARY KEY, name TEXT UNIQUE, kind INTEGER DEFAULT 0,
			lastUpdate INTEGER DEFAULT 0, error INTEGER DEFAULT 0, attributes TEXT)');
		$this->pdo->exec('CREATE TABLE tag (id INTEGER PRIMARY KEY, name TEXT UNIQUE)');
		$this->dao = new FreshRSS_CategoryDAOSQLite(currentPdo: $this->pdo);
	}

	private function feed(int $id, string $name, int $unread = 0): FreshRSS_Feed {
		$feed = new FreshRSS_Feed('https://example.net/' . $name, false);
		$feed->_id($id);
		$feed->_name($name);
		$feed->_priority(FreshRSS_Feed::PRIORITY_FEED);
		$feed->_nbNotRead($unread);
		return $feed;
	}

	public function testAddingAnExistingFeedReplacesOnlyThatFeed(): void {
		$first = $this->feed(1, 'First', 2);
		$second = $this->feed(2, 'Second', 3);
		$category = new FreshRSS_Category('Category', 5, [$first, $second]);
		self::assertSame(5, $category->nbNotRead());

		$replacement = $this->feed(1, 'Replacement', 7);
		$category->addFeed($replacement);
		self::assertSame($replacement, $category->feeds()[1]);
		self::assertSame($second, $category->feeds()[2]);
		self::assertSame(2, $category->nbFeeds());
		self::assertSame(10, $category->nbNotRead());
		self::assertSame($category, $replacement->category());
	}

	public function testUnsavedFeedsDoNotCollideWithPersistedIds(): void {
		$first = $this->feed(0, 'First');
		$second = $this->feed(0, 'Second');
		$saved = $this->feed(1, 'Saved');
		$category = new FreshRSS_Category('Category', 5, [$first, $second]);
		$category->addFeed($saved);

		self::assertSame(3, $category->nbFeeds());
		self::assertContains($first, $category->feeds());
		self::assertContains($second, $category->feeds());
		self::assertSame($saved, $category->feeds()[1]);
	}

	public function testReplacingFeedsResetsCountsErrorsAndBackReferences(): void {
		$error = $this->feed(2, 'Error', 8);
		$error->_error(1);
		$category = new FreshRSS_Category('Category', 5, [$error]);
		self::assertTrue($category->hasFeedsWithError());
		self::assertSame(8, $category->nbNotRead());

		$replacement = $this->feed(3, 'Replacement', 4);
		$hidden = $this->feed(4, 'Hidden', 9);
		$hidden->_priority(FreshRSS_Feed::PRIORITY_HIDDEN);
		$hidden->_error(1);
		$category->_feeds([$replacement, $hidden]);
		self::assertSame(2, $category->nbFeeds());
		self::assertSame(4, $category->nbNotRead());
		self::assertFalse($category->hasFeedsWithError());
		self::assertSame($category, $replacement->category());

		$category->_feeds([]);
		self::assertSame([], $category->feeds());
		self::assertSame(0, $category->nbFeeds());
		self::assertSame(0, $category->nbNotRead());
		self::assertFalse($category->hasFeedsWithError());
	}

	public function testDuplicateFeedIdsAreCountedOnlyOnce(): void {
		$category = new FreshRSS_Category('Category', 5, [
			$this->feed(2, 'Old', 8),
			$this->feed(2, 'New', 3),
		]);
		self::assertSame(1, $category->nbFeeds());
		self::assertSame(3, $category->nbNotRead());
	}

	public function testZeroUnreadCountIsCached(): void {
		$feed = $this->createMock(FreshRSS_Feed::class);
		$feed->method('id')->willReturn(2);
		$feed->method('priority')->willReturn(FreshRSS_Feed::PRIORITY_FEED);
		$feed->expects(self::once())->method('nbNotRead')->willReturn(0);
		$category = new FreshRSS_Category('Category', 5, [$feed]);
		self::assertSame(0, $category->nbNotRead());
		self::assertSame(0, $category->nbNotRead());
	}

	public function testInvalidAttributesDoNotInsertACategory(): void {
		self::assertFalse($this->dao->addCategory(['name' => 'Invalid', 'attributes' => ['value' => "\xFF"]]));
		self::assertSame(0, $this->dao->count());
	}

	public function testInvalidAttributesDoNotOverwriteACategory(): void {
		$id = $this->dao->addCategory(['id' => 2, 'name' => 'Original', 'attributes' => ['value' => 'preserved']]);
		self::assertSame(2, $id);
		self::assertFalse($this->dao->updateCategory($id, ['name' => 'Changed', 'kind' => 0, 'attributes' => ['value' => INF]]));
		$category = $this->dao->searchById($id);
		self::assertNotNull($category);
		self::assertSame('Original', $category->name());
		self::assertSame('preserved', $category->attributeString('value'));
	}

	public function testAutomaticMigrationPreservesTheCallersTransaction(): void {
		$this->pdo->exec('DROP TABLE category');
		$this->pdo->exec('CREATE TABLE category (id INTEGER PRIMARY KEY, name TEXT UNIQUE,
			lastUpdate INTEGER DEFAULT 0, error INTEGER DEFAULT 0, attributes TEXT)');
		$this->pdo->beginTransaction();
		$this->pdo->exec("INSERT INTO tag(name) VALUES ('Uncommitted')");
		self::assertFalse($this->dao->addCategory(['name' => 'New']));
		self::assertTrue($this->pdo->inTransaction());
		self::assertTrue($this->pdo->rollBack());
		self::assertSame(0, $this->dao->fetchInt('SELECT COUNT(*) FROM tag'));
		self::assertNotContains('kind', $this->dao->fetchColumn("PRAGMA table_info('category')", 1) ?? []);

		self::assertIsInt($this->dao->addCategory(['name' => 'New']));
		self::assertContains('kind', $this->dao->fetchColumn("PRAGMA table_info('category')", 1) ?? []);
		self::assertSame(1, $this->dao->count());
	}
}
