<?php

namespace App\RealEstate\Pagination;

use Illuminate\Pagination\Cursor;

/** Курсор API Real Estate — непрозрачная строка, передаётся обратно как есть. */
final class ApiCursor extends Cursor
{
    public function __construct(private readonly string $raw)
    {
        parent::__construct([]);
    }

    public function encode(): string
    {
        return $this->raw;
    }
}
