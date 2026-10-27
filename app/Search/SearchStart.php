<?php

namespace App\Search;

use App\Models\ListingSearch;

/** Итог старта: новый поиск, уже идущий такой же или свежий готовый — от этого зависит, что сказать человеку. */
final readonly class SearchStart
{
    public const NEW = 'new';

    public const RUNNING = 'running';

    public const REUSED = 'reused';

    /** @param  self::NEW|self::RUNNING|self::REUSED  $kind */
    public function __construct(public ListingSearch $search, public string $kind) {}
}
