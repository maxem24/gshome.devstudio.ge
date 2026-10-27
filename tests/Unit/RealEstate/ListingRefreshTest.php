<?php

namespace Tests\Unit\RealEstate;

use App\RealEstate\ListingRefresh;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingRefreshTest extends TestCase
{
    public function test_parses_start_response_from_the_docs(): void
    {
        $r = ListingRefresh::fromApi(RealEstateFake::refresh([
            'items' => [
                ['id' => 123, 'source' => 'ss_ge', 'source_url' => 'https://home.ss.ge/x', 'status' => 'active', 'outcome' => 'pending',
                    'checked_at' => '2026-10-10T09:12:00+04:00', 'deactivated_at' => null, 'error' => null, 'paused_until' => null],
                ['id' => 456, 'source' => 'myhome_ge', 'source_url' => 'https://www.myhome.ge/x', 'status' => 'active', 'outcome' => 'failed',
                    'checked_at' => null, 'deactivated_at' => null, 'error' => 'myhome временно ограничил запросы — проверим после 20:09.',
                    'paused_until' => '2026-10-13T20:09:00+04:00'],
            ],
            'eta_seconds' => 15, 'poll_after_ms' => 2000, 'paused' => true,
        ]));

        $this->assertSame('9f3c2a1e-7b4d-4c55-9a0e-1d2f3a4b5c6d', $r->id);
        $this->assertSame('queued', $r->status);
        $this->assertSame(15, $r->etaSeconds);
        $this->assertSame(2000, $r->pollAfterMs);
        $this->assertTrue($r->paused);
        $this->assertFalse($r->truncated);
        $this->assertNotNull($r->deadlineAt);
        $this->assertFalse($r->finished());
        $this->assertNull($r->listing);
        $this->assertCount(2, $r->items);
        $this->assertSame('pending', $r->items[0]->outcome);
        $this->assertSame('failed', $r->items[1]->outcome);
        $this->assertSame('myhome временно ограничил запросы — проверим после 20:09.', $r->items[1]->error);
        $this->assertSame('2026-10-13 20:09', $r->items[1]->pausedUntil?->format('Y-m-d H:i'));
    }

    public function test_unknown_outcome_becomes_failed_and_poll_interval_has_a_floor(): void
    {
        $r = ListingRefresh::fromApi(RealEstateFake::refresh([
            'status' => 'stale', 'poll_after_ms' => 10,
            'items' => [['id' => 13, 'source' => 'ss_ge', 'status' => 'active', 'outcome' => 'teleported']],
            'listing' => RealEstateFake::listing(),
        ]));

        $this->assertSame('failed', $r->items[0]->outcome);
        $this->assertSame(1000, $r->pollAfterMs);
        $this->assertTrue($r->finished());
        $this->assertTrue($r->stale());
        $this->assertSame(13, $r->listing['id']);
    }

    public function test_bad_format_throws(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        ListingRefresh::fromApi(['status' => 'done']);
    }
}
