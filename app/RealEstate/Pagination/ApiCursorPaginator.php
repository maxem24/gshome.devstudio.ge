<?php

namespace App\RealEstate\Pagination;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * Курсорная пагинация поверх API: «вперёд/назад» — курсоры из ответа API, а не
 * вычисленные Laravel по последней строке. Кнопки Filament берут nextCursor()->encode().
 *
 * Laravel переиндексирует строки (values()), ключи 0..n — id объявления брать из $record['id'], а не из ключа строки.
 *
 * @extends CursorPaginator<array-key, array<string, mixed>>
 */
final class ApiCursorPaginator extends CursorPaginator
{
    /**
     * @param  iterable<array-key, array<string, mixed>>  $items
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        iterable $items,
        int $perPage,
        ?string $currentCursor,
        private readonly ?string $nextRaw,
        private readonly ?string $prevRaw,
        array $options = [],
    ) {
        parent::__construct($items, $perPage, $currentCursor !== null ? new ApiCursor($currentCursor) : null, $options);
    }

    /** @param  array<string, mixed>  $options */
    public static function emptyPage(int $perPage, array $options = []): self
    {
        return new self([], $perPage, null, null, null, $options);
    }

    public function nextCursor(): ?Cursor
    {
        return $this->nextRaw === null ? null : new ApiCursor($this->nextRaw);
    }

    public function previousCursor(): ?Cursor
    {
        return $this->prevRaw === null ? null : new ApiCursor($this->prevRaw);
    }

    public function hasMorePages(): bool
    {
        return $this->nextRaw !== null;
    }

    public function onFirstPage(): bool
    {
        return $this->prevRaw === null;
    }
}
