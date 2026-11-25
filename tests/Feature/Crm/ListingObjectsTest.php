<?php

namespace Tests\Feature\Crm;

use App\Crm\Exceptions\CrmForbidden;
use App\Crm\ListingObjects;
use App\Enums\DealStatus;
use App\Enums\Direction;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingObjectsTest extends TestCase
{
    use RefreshDatabase;

    private User $nino;

    private User $giorgi;

    protected function setUp(): void
    {
        parent::setUp();
        $lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
        $this->nino = User::factory()->agent($lead)->create();
        $this->giorgi = User::factory()->agent($lead)->create();
    }

    /** @param  array<string, mixed>  $overrides */
    private function listing(int $id = 41, ?int $clusterId = null, array $overrides = []): Listing
    {
        return Listing::fromApi(RealEstateFake::listing(array_replace([
            'id' => $id,
            'cluster' => $clusterId === null ? null : ['id' => $clusterId, 'listings_count' => 2, 'phones_e164' => [], 'listings' => []],
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
            'price' => ['usd' => 600.0, 'gel' => 1650.0], 'rooms' => 2,
            'subdistrict' => ['id' => 101, 'title' => 'Ваке'],
            'title' => ['ru' => 'Квартира'],
        ], $overrides)), 'ru');
    }

    private function pin(ListingObject $object, User $user, string $key): ListingPin
    {
        return ListingPin::query()->create([
            'listing_id' => $object->listing_id, 'cluster_id' => $object->cluster_id, 'pin_key' => $key,
            'direction' => $object->direction, 'user_id' => $user->id, 'status' => ListingPin::ACTIVE,
            'object_id' => $object->id, 'last_activity_at' => now(),
        ]);
    }

    private function deal(ListingPin $pin, Client $client): Deal
    {
        return Deal::query()->create([
            'listing_pin_id' => $pin->id, 'client_id' => $client->id, 'user_id' => $pin->user_id,
            'stage' => 'new', 'status' => DealStatus::Open,
        ]);
    }

    public function test_open_for_creates_once_per_cluster(): void
    {
        $objects = app(ListingObjects::class);

        $a = $objects->openFor($this->listing(41, 5));
        $b = $objects->openFor($this->listing(42, 5));

        $this->assertSame($a->id, $b->id);
        $this->assertSame('c:5', $a->object_key);
        $this->assertSame(Direction::Sale, $a->direction);
        $this->assertSame(1, ListingObject::query()->count());
        $this->assertSame(1650.0, (float) $a->fresh()?->price_gel);
    }

    public function test_find_by_listing_id_when_key_is_stale(): void
    {
        $stale = ListingObject::factory()->create(['object_key' => 'l:41', 'listing_id' => 41]);

        $found = app(ListingObjects::class)->openFor($this->listing(41, 5));

        $this->assertSame($stale->id, $found->id);
    }

    public function test_find_open_merges_several_into_the_oldest(): void
    {
        $old = ListingObject::factory()->create(['object_key' => 'l:41', 'listing_id' => 41]);
        $young = ListingObject::factory()->create(['object_key' => 'c:5', 'listing_id' => 42, 'cluster_id' => 5]);

        $found = app(ListingObjects::class)->findOpen(41, 5);

        $this->assertSame($old->id, $found?->id);
        $this->assertSame($old->id, $young->fresh()?->merged_into_id);
    }

    public function test_merge_moves_pins_and_closes_younger(): void
    {
        $keep = ListingObject::factory()->create(['object_key' => 'l:41', 'listing_id' => 41]);
        $gone = ListingObject::factory()->create(['object_key' => 'c:5', 'listing_id' => 42, 'cluster_id' => 5]);
        $this->pin($keep, $this->nino, 'l:41');
        $pin = $this->pin($gone, $this->giorgi, 'c:5');

        app(ListingObjects::class)->merge($keep, $gone);

        $gone->refresh();
        $this->assertSame(ListingObject::CLOSED, $gone->status);
        $this->assertSame('merged_into', $gone->closed_reason);
        $this->assertSame($keep->id, $gone->merged_into_id);
        $this->assertSame($keep->id, $pin->fresh()?->object_id);
        $this->assertSame(ListingPin::ACTIVE, $pin->refresh()->status);
    }

    public function test_merge_same_agent_keeps_older_pin_and_moves_deals(): void
    {
        $keep = ListingObject::factory()->create(['object_key' => 'l:41', 'listing_id' => 41]);
        $gone = ListingObject::factory()->create(['object_key' => 'c:5', 'listing_id' => 42, 'cluster_id' => 5]);
        $older = $this->pin($keep, $this->nino, 'l:41');
        $younger = $this->pin($gone, $this->nino, 'c:5');
        $moved = $this->deal($younger, Client::factory()->create());
        $same = Client::factory()->create();
        $this->deal($older, $same);
        $dup = $this->deal($younger, $same);

        app(ListingObjects::class)->merge($keep, $gone);

        $younger->refresh();
        $this->assertSame(ListingPin::CLOSED, $younger->status);
        $this->assertSame('merged', $younger->closed_reason);
        $this->assertSame(ListingPin::ACTIVE, $older->fresh()?->status);
        $this->assertSame($older->id, $moved->fresh()?->listing_pin_id);
        $this->assertSame(DealStatus::Open, $moved->refresh()->status);
        $this->assertSame(DealStatus::Unavailable, $dup->refresh()->status);
        $this->assertNotNull($dup->closed_at);
        $this->assertSame($younger->id, $dup->listing_pin_id);
    }

    public function test_lock_rejects_closed_object(): void
    {
        $closed = ListingObject::factory()->closed('fake')->create();
        $merged = ListingObject::factory()->closed('merged_into')->create(['merged_into_id' => $closed->id]);

        try {
            app(ListingObjects::class)->lock($closed);
            $this->fail('closed');
        } catch (CrmForbidden $e) {
            $this->assertSame('Object closed.', $e->getMessage());
        }
        try {
            app(ListingObjects::class)->lock($merged);
            $this->fail('merged');
        } catch (CrmForbidden $e) {
            $this->assertSame('Object merged into #'.$closed->id.'.', $e->getMessage());
        }

        $open = ListingObject::factory()->create();
        $this->assertSame($open->id, app(ListingObjects::class)->lock($open)->id);
    }

    public function test_close_if_abandoned(): void
    {
        $object = ListingObject::factory()->create();
        $pin = $this->pin($object, $this->nino, 'x');
        $objects = app(ListingObjects::class);

        $objects->closeIfAbandoned($object);
        $this->assertTrue($object->fresh()?->isOpen());

        $pin->update(['status' => ListingPin::CLOSED, 'closed_reason' => 'released', 'closed_at' => now()]);
        $objects->closeIfAbandoned($object);
        $this->assertSame('abandoned', $object->fresh()?->closed_reason);
    }

    public function test_snapshot_fills_fields(): void
    {
        $object = app(ListingObjects::class)->openFor($this->listing());

        $object->refresh();
        $this->assertSame(600.0, (float) $object->price_usd);
        $this->assertSame(1650.0, (float) $object->price_gel);
        $this->assertSame(2, $object->rooms);
        $this->assertSame(101, $object->subdistrict_id);
        $this->assertSame('Квартира', $object->title);
        $this->assertNotNull($object->snapshot_at);
    }

    public function test_snapshot_writes_only_on_change(): void
    {
        $objects = app(ListingObjects::class);
        $object = $objects->openFor($this->listing());
        $at = $object->fresh()?->snapshot_at;

        $this->travel(5)->minutes();
        $this->assertFalse($objects->snapshot($object, $this->listing()));
        $this->assertEquals($at, $object->fresh()?->snapshot_at);

        $this->assertTrue($objects->snapshot($object, $this->listing(41, null, ['price' => ['usd' => 650.0, 'gel' => 1650.0]])));
        $this->assertSame(650.0, (float) $object->fresh()?->price_usd);
    }

    public function test_snapshot_touch_marks_fresh_without_changes(): void
    {
        $objects = app(ListingObjects::class);
        $object = $objects->openFor($this->listing());

        $this->travel(5)->minutes();
        $this->assertFalse($objects->snapshot($object, $this->listing(), touch: true));
        $this->assertTrue($object->fresh()?->snapshot_at?->isAfter(now()->subMinute()));
    }

    public function test_merge_into_closed_keep_is_noop(): void
    {
        $keep = ListingObject::factory()->closed('fake')->create(['object_key' => 'l:41', 'listing_id' => 41]);
        $gone = ListingObject::factory()->create(['object_key' => 'c:5', 'listing_id' => 42, 'cluster_id' => 5]);
        $pin = $this->pin($gone, $this->nino, 'c:5');

        app(ListingObjects::class)->merge($keep, $gone);

        $this->assertTrue($gone->fresh()?->isOpen());
        $this->assertNull($gone->refresh()->merged_into_id);
        $this->assertSame($gone->id, $pin->refresh()->object_id);
    }

    public function test_find_open_does_not_return_closed_object(): void
    {
        ListingObject::factory()->closed('fake')->create(['object_key' => 'l:41', 'listing_id' => 41]);

        $this->assertNull(app(ListingObjects::class)->findOpen(41, null));
    }

    public function test_snapshot_writes_on_gel_only_change(): void
    {
        $objects = app(ListingObjects::class);
        $object = $objects->openFor($this->listing());

        $this->assertTrue($objects->snapshot($object, $this->listing(41, null, ['price' => ['usd' => 600.0, 'gel' => 1700.0]])));
        $this->assertSame(1700.0, (float) $object->fresh()?->price_gel);
    }
}
