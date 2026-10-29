<?php

namespace App\Search;

use App\RealEstate\LinkImport;

/** Итог «По ссылке»: поиск (выдача сайта) или объявление — готовое либо поставленное сборщику. */
final readonly class LinkStart
{
    private function __construct(
        public ?SearchStart $search,
        public ?LinkImport $listing,
    ) {}

    public static function search(SearchStart $start): self
    {
        return new self($start, null);
    }

    public static function listing(LinkImport $import): self
    {
        return new self(null, $import);
    }
}
