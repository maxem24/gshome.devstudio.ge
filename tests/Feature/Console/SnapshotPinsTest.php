<?php

namespace Tests\Feature\Console;

use App\Enums\Direction;
use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class SnapshotPinsTest extends TestCase
{
    use RefreshDatabase;

    private function pin(int $listingId, Direction $direction, ?string $snapshotAt = null): ListingPin
    {
        return ListingPin::query()->create([
            'listing_id' => $listingId, 'pin_key' => 'l'.$listingId, 'direction' => $direction,
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
}
