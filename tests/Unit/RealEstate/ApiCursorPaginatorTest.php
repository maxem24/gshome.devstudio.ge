<?php

namespace Tests\Unit\RealEstate;

use App\RealEstate\Pagination\ApiCursorPaginator;
use PHPUnit\Framework\TestCase;

class ApiCursorPaginatorTest extends TestCase
{
    public function test_first_page_with_next_cursor(): void
    {
        $p = new ApiCursorPaginator([13 => ['id' => 13], 12 => ['id' => 12]], 2, null, 'eyJuZXh0Ijox', null);

        $this->assertTrue($p->onFirstPage());
        $this->assertTrue($p->hasMorePages());
        $this->assertSame('eyJuZXh0Ijox', $p->nextCursor()?->encode());
        $this->assertNull($p->previousCursor());
        $this->assertSame([13, 12], array_column($p->items(), 'id'));
    }

    public function test_middle_page_has_both_cursors(): void
    {
        $p = new ApiCursorPaginator([['id' => 1]], 1, 'cur', 'next', 'prev');

        $this->assertFalse($p->onFirstPage());
        $this->assertTrue($p->hasMorePages());
        $this->assertSame('prev', $p->previousCursor()?->encode());
    }

    public function test_last_page(): void
    {
        $p = new ApiCursorPaginator([['id' => 1]], 25, 'cur', null, 'prev');

        $this->assertFalse($p->hasMorePages());
        $this->assertNull($p->nextCursor());
        $this->assertTrue($p->hasPages());
    }

    public function test_empty_page(): void
    {
        $p = ApiCursorPaginator::emptyPage(25);

        $this->assertCount(0, $p->items());
        $this->assertFalse($p->hasPages());
    }
}
