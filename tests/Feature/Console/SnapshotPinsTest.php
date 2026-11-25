<?php

namespace Tests\Feature\Console;

use App\Enums\Direction;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class SnapshotPinsTest extends TestCase
{
    use RefreshDatabase;

    private function pin(int $listingId, Direction $direction, ?string $snapshotAt = null): ListingPin
    {
        return ListingPin::query()->create([
            'listing_id' => $listingId, 'pin_key' => 'l'.$listingId, 'object_id' => ListingObject::factory()->create()->id, 'direction' => $direction,
            'user_id' => User::factory()->create()->id, 'status' => ListingPin::ACTIVE,
            'snapshot_at' => $snapshotAt, 'last_activity_at' => now(),
        ]);
    }

    public function test_fills_stale_rent_pins_and_skips_missing(): void
    {
        $fresh = $this->pin(21, Direction::Rent);
        $gone = $this->pin(22, Direction::Rent);
        $sale = $this->pin(23, Direction::Sale);
        RealEstateFake::fake(cards: [
            21 => RealEstateFake::listing(['id' => 21, 'price' => ['usd' => 700.0], 'rooms' => 3, 'subdistrict' => ['id' => 5, 'title' => 'X']]),
            23 => RealEstateFake::listing(['id' => 23]),
        ]);

        $this->artisan('pins:snapshot')->expectsOutputToContain('22')->assertSuccessful();

        $this->assertSame(700.0, (float) $fresh->fresh()?->price_usd);
        $this->assertSame(5, $fresh->fresh()?->subdistrict_id);
        $this->assertNull($gone->fresh()?->snapshot_at);
        $this->assertNull($sale->fresh()?->snapshot_at);
    }

    public function test_stale_option_skips_fresh(): void
    {
        $this->pin(31, Direction::Rent, now()->subHour()->toIso8601String());
        RealEstateFake::fake(cards: [31 => RealEstateFake::listing(['id' => 31])]);

        $this->artisan('pins:snapshot', ['--stale' => 24])->assertSuccessful();

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/listings/31'));
    }

    /** Ночная проверка без изменений тоже считается свежей: иначе --stale опрашивал бы всех каждую ночь. */
    public function test_unchanged_pin_is_not_requested_again_the_same_day(): void
    {
        $card = RealEstateFake::listing(['id' => 41, 'price' => ['usd' => 600.0], 'rooms' => 2, 'subdistrict' => ['id' => 5, 'title' => 'X']]);
        $pin = $this->pin(41, Direction::Rent, now()->subHours(30)->toIso8601String());
        $pin->update(['price_usd' => 600, 'rooms' => 2, 'subdistrict_id' => 5]);
        RealEstateFake::fake(cards: [41 => $card]);

        $this->artisan('pins:snapshot', ['--stale' => 24])->assertSuccessful();
        Cache::flush(); // карточка кешируется клиентом API — второй запуск должен решать сам, а не по кешу
        $this->artisan('pins:snapshot', ['--stale' => 24])->assertSuccessful();

        $this->assertCount(1, array_filter(Http::recorded()->all(), fn (array $pair): bool => str_contains($pair[0]->url(), '/listings/41')));
    }

    public function test_stops_on_rate_limit(): void
    {
        $this->pin(51, Direction::Rent);
        $this->pin(52, Direction::Rent);
        RealEstateFake::configure();
        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '60'])]);

        $this->artisan('pins:snapshot')->assertSuccessful();

        Http::assertSentCount(1);
    }
}
