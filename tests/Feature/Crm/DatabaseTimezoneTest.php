<?php

namespace Tests\Feature\Crm;

use App\Models\ListingPin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_timezone_matches_app(): void
    {
        $this->assertSame(config('app.timezone'), DB::selectOne('show timezone')->TimeZone);
    }

    public function test_timestamptz_keeps_the_instant(): void
    {
        $at = Carbon::parse('2026-10-25 15:00:00', 'Asia/Tbilisi');
        $pin = ListingPin::query()->create([
            'listing_id' => 1, 'pin_key' => 'l:1', 'direction' => 'sale',
            'user_id' => User::factory()->owner()->create()->id, 'status' => 'active', 'last_activity_at' => $at,
        ]);

        $utc = DB::selectOne("select to_char(last_activity_at at time zone 'UTC', 'HH24:MI') as t from listing_pins where id = ?", [$pin->id])->t;
        $this->assertSame('11:00', $utc);
        $this->assertTrue($pin->fresh()->last_activity_at->equalTo($at));
    }
}
