<?php
declare(strict_types=1);

namespace FreshRSS\Services\Reader;

use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS_Auth;
use FreshRSS_Context;
use FreshRSS_DatabaseDAO;
use FreshRSS_EntryDAO;
use FreshRSS_Factory;
use FreshRSS_FeedDAO;
use FreshRSS_user_Controller;
use InvalidArgumentException;
use Minz_User;

/**
 * Read-only storage information for the authenticated reader account.
 * The supplied DAOs and directory must belong to that same account.
 * @phpstan-type Archive array{name:string,bytes:int,modifiedAt:int,active:bool}
 * @phpstan-type Summary array{timestamp:int,database:array{type:string,bytes:int|null,totalBytes?:int|null},
 *     articles:array{total:int|null,read:int|null,unread:int|null,favourites:int|null},feeds:int|null,
 *     backups:array{sqliteAvailable:bool,archives:list<Archive>,bytes:int}}
 */
final class StorageService {

	public function __construct(
		private readonly FreshRSS_DatabaseDAO $database,
		private readonly FreshRSS_EntryDAO $entries,
		private readonly FreshRSS_FeedDAO $feeds,
		private readonly string $directory,
		private readonly string $databaseType,
		private readonly string $databaseName = '',
		private readonly string $tablePrefix = '',
	) {
		if (!in_array($databaseType, ['sqlite', 'mysql', 'pgsql'], true)) {
			throw new InvalidArgumentException('Unsupported database type');
		}
	}

	/** @return Summary
	 * @throws ApiException
	 */
	public static function describe(): array {
		$username = Minz_User::name();
		if (!FreshRSS_Auth::hasAccess() || $username === null || !FreshRSS_user_Controller::checkUsername($username)) {
			throw new ApiException('forbidden', 403);
		}
		$db = FreshRSS_Context::systemConf()->db;
		$service = new self(
			FreshRSS_Factory::createDatabaseDAO(),
			FreshRSS_Factory::createEntryDao(),
			FreshRSS_Factory::createFeedDao(),
			USERS_PATH . '/' . $username,
			$db['type'],
			$db['base'],
			$db['prefix'] . $username . '_',
		);
		return $service->summary(FreshRSS_Auth::hasAccess('admin'));
	}

	/** The caller determines administrator access; describe() supplies the authenticated scope.
	 * @return Summary
	 */
	public function summary(bool $admin = false): array {
		$states = $this->entries->countAsStates();
		$database = ['type' => $this->databaseType, 'bytes' => $this->size(false)];
		if ($admin) {
			$database['totalBytes'] = $this->size(true);
		}
		$archives = $this->archives();
		$backupBytes = 0;
		foreach ($archives as $archive) {
			if (!$archive['active']) {
				$backupBytes += $archive['bytes'];
			}
		}
		return [
			'timestamp' => time(),
			'database' => $database,
			'articles' => ['total' => self::count($states['all']), 'read' => self::count($states['read']),
				'unread' => self::count($states['unread']), 'favourites' => self::count($states['favorites'])],
			'feeds' => self::count($this->feeds->count()),
			'backups' => ['sqliteAvailable' => $archives !== [], 'archives' => $archives, 'bytes' => $backupBytes],
		];
	}

	private static function count(?int $count): ?int {
		return $count !== null && $count >= 0 ? $count : null;
	}

	private function size(bool $all): ?int {
		if ($this->databaseType !== 'mysql') {
			return self::count($this->database->size($all));
		}
		// DatabaseDAO::size() runs ANALYZE TABLE on MySQL; a status request must only read metadata.
		$sql = <<<'SQL'
			SELECT SUM(DATA_LENGTH + INDEX_LENGTH + DATA_FREE)
			FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema
			SQL;
		$values = [':schema' => $this->databaseName];
		if (!$all) {
			$sql .= "\nAND TABLE_NAME LIKE :prefix";
			$values[':prefix'] = addcslashes($this->tablePrefix, '\\%_') . '%';
		}
		return self::count($this->database->fetchInt($sql, $values));
	}

	/** @return list<Archive> */
	private function archives(): array {
		$directory = realpath($this->directory);
		if ($directory === false || !is_dir($directory)) {
			return [];
		}
		$archives = [];
		foreach (glob($directory . '/*.sqlite', GLOB_NOSORT) ?: [] as $path) {
			$name = basename($path);
			// Match the existing SQLite download action's filename rules, without following links.
			if (!preg_match('/^(?![.-])[0-9a-zA-Z_.@ #&()~\-]{1,128}\.sqlite$/D', $name)
				|| is_link($path) || !is_file($path) || !is_readable($path)) {
				continue;
			}
			$resolved = realpath($path);
			$size = @filesize($path);
			$mtime = @filemtime($path);
			if ($resolved === false || dirname($resolved) !== $directory || $size === false || $size <= 0 || $mtime === false || $mtime <= 0) {
				continue;
			}
			$archives[] = ['name' => $name, 'bytes' => $size, 'modifiedAt' => $mtime,
				'active' => $this->databaseType === 'sqlite' && $name === 'db.sqlite'];
		}
		usort($archives, static fn(array $left, array $right): int =>
			($right['modifiedAt'] <=> $left['modifiedAt']) ?: strcmp($left['name'], $right['name']));
		return $archives;
	}
}
