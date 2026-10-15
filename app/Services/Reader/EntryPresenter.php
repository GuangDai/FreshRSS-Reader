<?php
declare(strict_types=1);

namespace FreshRSS\Services\Reader;

use FreshRSS_Entry;

final class EntryPresenter {
	public const ENTRY_ID_MAX = '9223372036854775807';
	public const ENTRY_ID_PATTERN = '^[1-9][0-9]{0,18}$';

	/** @return numeric-string|null */
	public static function entryId(string $value): ?string {
		if (!ctype_digit($value) || preg_match('/' . self::ENTRY_ID_PATTERN . '/D', $value) !== 1 ||
			(strlen($value) === strlen(self::ENTRY_ID_MAX) && strcmp($value, self::ENTRY_ID_MAX) > 0)) {
			return null;
		}
		return $value;
	}

	/** @return array{id:string,feedId:int,title:string,author:string,date:int,read:bool,starred:bool,link:string} */
	public static function summary(FreshRSS_Entry $entry): array {
		return [
			'id' => $entry->id(), 'feedId' => $entry->feedId(),
			'title' => html_entity_decode($entry->title(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'author' => html_entity_decode(implode(', ', $entry->authors()), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'date' => (int)$entry->date(true), 'read' => $entry->isRead() ?? false, 'starred' => $entry->isFavorite() ?? false,
			'link' => html_entity_decode($entry->link(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
		];
	}
}
