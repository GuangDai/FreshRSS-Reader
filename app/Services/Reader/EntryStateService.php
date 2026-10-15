<?php
declare(strict_types=1);

namespace FreshRSS\Services\Reader;

use Closure;
use FreshRSS\Exceptions\Reader\ApiException;
use FreshRSS\Utils\Http\ApiInput;
use FreshRSS_EntryDAO;
use InvalidArgumentException;
use LengthException;
use Minz_Log;
use Throwable;

/** State changes shared by the single-item and batch APIs; the supplied DAO determines the current user. */
final class EntryStateService {
	private readonly Closure $invalidate;

	/** @param (Closure():void)|null $invalidate */
	public function __construct(private readonly FreshRSS_EntryDAO $entries, ?Closure $invalidate = null) {
		$this->invalidate = $invalidate ?? static function (): void { invalidateHttpCache(); };
	}

	/** @param array<string,mixed> $params
	 * @return array{ids:list<numeric-string>,changed:int,atomic:false}
	 * @throws ApiException
	 */
	public function batch(array $params, int $maximum): array {
		try {
			$request = ApiInput::stateBatch($params, $maximum);
		} catch (LengthException $exception) {
			throw new ApiException($exception->getMessage(), 413, ['maxBatchItems' => $maximum], $exception);
		} catch (InvalidArgumentException $exception) {
			throw new ApiException($exception->getMessage(), previous: $exception);
		}
		$existing = $this->entries->existingIds($request['ids']);
		if ($existing === null) {
			throw new ApiException('read_failed', 500);
		}
		if (count($existing) !== count($request['ids'])) {
			throw new ApiException('not_found', 404);
		}
		return ['ids' => $request['ids'], 'changed' => $this->write($request['ids'], $request['field'], $request['value']), 'atomic' => false];
	}

	/** @param array<string,mixed> $params
	 * @return array{id:numeric-string,read:bool,starred:bool}
	 * @throws ApiException
	 */
	public function state(array $params): array {
		try {
			$request = ApiInput::stateBatch(['ids' => [$params['id'] ?? null]] + $params, 1);
		} catch (InvalidArgumentException $exception) {
			throw new ApiException('invalid_state', previous: $exception);
		}
		$id = $request['ids'][0];
		$entry = null;
		foreach ($this->entries->listSummaries('Z', idMax: $id, limit: 1) as $candidate) {
			$entry = $candidate;
		}
		if ($entry === null || $entry->id() !== $id) {
			throw new ApiException('not_found', 404);
		}
		$this->write([$id], $request['field'], $request['value']);
		return ['id' => $id, 'read' => $request['field'] === 'read' ? $request['value'] : ($entry->isRead() ?? false),
			'starred' => $request['field'] === 'starred' ? $request['value'] : ($entry->isFavorite() ?? false)];
	}

	/** @param list<numeric-string> $ids
	 * @param 'read'|'starred' $field
	 * @throws ApiException
	 */
	private function write(array $ids, string $field, bool $value): int {
		try {
			$changed = $field === 'read' ? $this->entries->markRead($ids, $value) : $this->entries->markFavorite($ids, $value);
		} catch (Throwable $exception) {
			Minz_Log::error('Reader state failed: ' . get_class($exception));
			throw new ApiException('write_failed', 500, ['atomic' => false], $exception);
		} finally {
			// A hook may fail after a database commit, so invalidate for every attempted write.
			($this->invalidate)();
		}
		if ($changed === false) {
			throw new ApiException('write_failed', 500, ['atomic' => false]);
		}
		return $changed;
	}

}
