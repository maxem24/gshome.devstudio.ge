<?php

namespace Tests\Feature\Crm;

use App\Crm\ListingObjects;
use App\Crm\Pins;
use App\Enums\Direction;
use App\Filament\Pages\ListingView;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class PinSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $overrides */
    private function listing(array $overrides = []): Listing
    {
        return Listing::fromApi(RealEstateFake::listing(array_replace([
            'price' => ['usd' => 600.0], 'rooms' => 2,
            'subdistrict' => ['id' => 101, 'title' => 'Ваке'],
            'title' => ['ru' => 'Квартира'],
        ], $overrides)), 'ru');
    }

    private function agent(): User
    {
        return User::factory()->agent(User::factory()->teamLead()->create())->create();
    }

    public function test_take_fills_snapshot(): void
    {
        $pin = app(Pins::class)->take($this->listing(), $this->agent());

        $object = $pin->object()->firstOrFail();
        $this->assertSame(600.0, (float) $object->price_usd);
        $this->assertSame(2, $object->rooms);
        $this->assertSame(101, $object->subdistrict_id);
        $this->assertSame('Квартира', $object->title);
        $this->assertNotNull($object->snapshot_at);
    }

    public function test_snapshot_writes_only_on_change(): void
    {
        $objects = app(ListingObjects::class);
        $pin = app(Pins::class)->take($this->listing(), $this->agent());
        $at = $pin->object?->fresh()?->snapshot_at;

        $this->travel(5)->minutes();
        $this->assertFalse($objects->snapshot($pin->object()->firstOrFail(), $this->listing()));
        $this->assertEquals($at, $pin->object?->fresh()?->snapshot_at);

        $this->assertTrue($objects->snapshot($pin->object()->firstOrFail(), $this->listing(['price' => ['usd' => 650.0]])));
        $this->assertSame(650.0, (float) $pin->object?->fresh()?->price_usd);
    }

    public function test_opening_card_refreshes_changed_price(): void
    {
        $agent = $this->agent();
        $pin = app(Pins::class)->take($this->listing(), $agent);
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing(['price' => ['usd' => 650.0], 'subdistrict' => ['id' => 101, 'title' => 'Ваке']])]);
        $this->actingAs($agent);

        Livewire::test(ListingView::class, ['listing' => 13])->assertOk();

        // Снимок живёт на объекте; колонки пина больше не обновляются при открытии карточки.
        $this->assertSame(650.0, (float) $pin->object?->fresh()?->price_usd);
        $this->assertSame(Direction::Sale, $pin->fresh()?->direction);
    }

    public function test_opening_a_duplicate_copy_keeps_the_snapshot(): void
    {
        $agent = $this->agent();
        $cluster = ['id' => 5, 'listings_count' => 2, 'phones_e164' => [], 'listings' => []];
        $pin = app(Pins::class)->take($this->listing(['cluster' => $cluster]), $agent);
        RealEstateFake::fake(cards: [14 => RealEstateFake::listing(['id' => 14, 'cluster' => $cluster, 'price' => ['usd' => 700.0], 'subdistrict' => null])]);
        $this->actingAs($agent);

        Livewire::test(ListingView::class, ['listing' => 14])->assertOk();

        $this->assertSame(600.0, (float) $pin->object?->fresh()?->price_usd);
        $this->assertSame(101, $pin->object?->fresh()?->subdistrict_id);
    }
}
