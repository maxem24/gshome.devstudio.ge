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

    private function object(int $listingId, Direction $direction, ?string $snapshotAt = null): ListingObject
    {
        $object = ListingObject::factory()->create([
            'listing_id' => $listingId, 'object_key' => 'l:'.$listingId, 'direction' => $direction, 'snapshot_at' => $snapshotAt,
        ]);
        $this->pinOn($object);

        return $object;
    }

    private function pinOn(ListingObject $object): ListingPin
    {
        return ListingPin::query()->create([
            'listing_id' => $object->listing_id, 'pin_key' => 'l'.$object->listing_id.'-'.User::query()->count(), 'object_id' => $object->id,
            'direction' => $object->direction, 'user_id' => User::factory()->create()->id, 'status' => ListingPin::ACTIVE, 'last_activity_at' => now(),
        ]);
    }

    public function test_fills_stale_rent_objects_and_skips_missing(): void
    {
        $fresh = $this->object(21, Direction::Rent);
        $gone = $this->object(22, Direction::Rent);
        $sale = $this->object(23, Direction::Sale);
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

    public function test_snapshot_one_api_call_per_object(): void
    {
        $object = $this->object(24, Direction::Rent);
        $this->pinOn($object);
        $this->pinOn($object);
        RealEstateFake::fake(cards: [24 => RealEstateFake::listing(['id' => 24, 'price' => ['usd' => 650.0]])]);

        $this->artisan('pins:snapshot')->assertSuccessful();

        $this->assertCount(1, array_filter(Http::recorded()->all(), fn (array $pair): bool => str_contains($pair[0]->url(), '/listings/24')));
        $this->assertSame(650.0, (float) $object->fresh()?->price_usd);
    }

    public function test_closed_objects_are_skipped(): void
    {
        $object = $this->object(25, Direction::Rent);
        $object->update(['status' => ListingObject::CLOSED, 'closed_reason' => 'abandoned', 'closed_at' => now()]);
        RealEstateFake::fake(cards: [25 => RealEstateFake::listing(['id' => 25])]);

        $this->artisan('pins:snapshot')->assertSuccessful();

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/listings/25'));
    }

    public function test_stale_option_skips_fresh(): void
    {
        $this->object(31, Direction::Rent, now()->subHour()->toIso8601String());
        RealEstateFake::fake(cards: [31 => RealEstateFake::listing(['id' => 31])]);

        $this->artisan('pins:snapshot', ['--stale' => 24])->assertSuccessful();

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/listings/31'));
    }

    /** Ночная проверка без изменений тоже считается свежей: иначе --stale опрашивал бы всех каждую ночь. */
    public function test_unchanged_object_is_not_requested_again_the_same_day(): void
    {
        $card = RealEstateFake::listing(['id' => 41, 'price' => ['usd' => 600.0], 'rooms' => 2, 'subdistrict' => ['id' => 5, 'title' => 'X']]);
        $object = $this->object(41, Direction::Rent, now()->subHours(30)->toIso8601String());
        $object->update(['price_usd' => 600, 'rooms' => 2, 'subdistrict_id' => 5]);
        RealEstateFake::fake(cards: [41 => $card]);

        $this->artisan('pins:snapshot', ['--stale' => 24])->assertSuccessful();
        Cache::flush(); // карточка кешируется клиентом API — второй запуск должен решать сам, а не по кешу
        $this->artisan('pins:snapshot', ['--stale' => 24])->assertSuccessful();

        $this->assertCount(1, array_filter(Http::recorded()->all(), fn (array $pair): bool => str_contains($pair[0]->url(), '/listings/41')));
    }

    /** Устаревший объект по копии 21 склеился со свежим по копии 22: снимок свежего чужой копией не трогаем. */
    public function test_merged_into_object_of_other_copy_keeps_its_snapshot(): void
    {
        $keep = $this->object(22, Direction::Rent, now()->toIso8601String());
        $keep->update(['object_key' => 'c:5', 'cluster_id' => 5, 'price_usd' => 500]);
        $this->object(21, Direction::Rent, now()->subDays(2)->toIso8601String());
        RealEstateFake::fake(cards: [21 => RealEstateFake::listing([
            'id' => 21, 'price' => ['usd' => 700.0], 'rooms' => 3, 'subdistrict' => ['id' => 5, 'title' => 'X'],
            'cluster' => ['id' => 5, 'listings_count' => 2, 'phones_e164' => [], 'listings' => [['id' => 22, 'source' => 'ss_ge'], ['id' => 21, 'source' => 'ss_ge']]],
        ])]);

        $this->artisan('pins:snapshot', ['--stale' => 24])->assertSuccessful();

        $this->assertSame(500.0, (float) $keep->fresh()?->price_usd);
        $this->assertSame(1, ListingObject::query()->where('status', ListingObject::OPEN)->count());
    }

    public function test_stops_on_rate_limit(): void
    {
        $this->object(51, Direction::Rent);
        $this->object(52, Direction::Rent);
        RealEstateFake::configure();
        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '60'])]);

        $this->artisan('pins:snapshot')->assertSuccessful();

        Http::assertSentCount(1);
    }
}
