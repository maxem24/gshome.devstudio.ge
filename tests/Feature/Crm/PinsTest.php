<?php

namespace Tests\Feature\Crm;

use App\Crm\Events\PinClosed;
use App\Crm\Events\PinTransferred;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Exceptions\PinTaken;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Enums\Direction;
use App\Models\Company;
use App\Models\ListingPin;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class PinsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $lead;

    private User $nino;

    private User $giorgi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->lead = User::factory()->teamLead($this->company, Direction::Sale)->create();
        $this->nino = User::factory()->agent($this->lead)->create();
        $this->giorgi = User::factory()->agent($this->lead)->create();
    }

    private function listing(int $id, ?int $clusterId = null, string $deal = 'sale'): Listing
    {
        return Listing::fromApi(RealEstateFake::listing([
            'id' => $id,
            'cluster' => $clusterId === null ? null : ['id' => $clusterId, 'listings_count' => 2, 'phones_e164' => [], 'listings' => []],
            'deal_type' => ['id' => $deal === 'sale' ? 1 : 2, 'slug' => $deal, 'title' => $deal],
        ]), 'ru');
    }

    public function test_take_pins_by_cluster(): void
    {
        $pin = app(Pins::class)->take($this->listing(41, 5), $this->nino);

        $this->assertSame('c:5', $pin->pin_key);
        $this->assertSame(41, $pin->listing_id);
        $this->assertSame(5, $pin->cluster_id);
        $this->assertSame($this->nino->id, $pin->user_id);
        $this->assertSame('active', $pin->status);
        $this->assertNotNull($pin->last_activity_at);
        $this->assertSame(1, Activity::query()->where('event', Journal::PIN_TAKEN)->where('causer_id', $this->nino->id)->count());
    }

    public function test_take_without_cluster_pins_by_listing(): void
    {
        $pin = app(Pins::class)->take($this->listing(13), $this->nino);

        $this->assertSame('l:13', $pin->pin_key);
        $this->assertNull($pin->cluster_id);
    }

    public function test_second_take_of_same_cluster_throws_pin_taken(): void
    {
        $pins = app(Pins::class);
        $pins->take($this->listing(41, 5), $this->nino);

        $this->expectException(PinTaken::class);
        $pins->take($this->listing(42, 5), $this->giorgi);
    }

    public function test_pin_taken_names_the_holder(): void
    {
        $pins = app(Pins::class);
        $first = $pins->take($this->listing(41, 5), $this->nino);

        try {
            $pins->take($this->listing(42, 5), $this->giorgi);
            $this->fail('PinTaken expected');
        } catch (PinTaken $e) {
            $this->assertTrue($e->pin->is($first));
        }
    }

    public function test_second_take_of_same_cluster_fails_even_bypassing_service(): void
    {
        app(Pins::class)->take($this->listing(41, 5), $this->nino);

        $this->expectException(UniqueConstraintViolationException::class);
        ListingPin::query()->create([
            'listing_id' => 42, 'cluster_id' => 5, 'pin_key' => 'c:5', 'direction' => 'sale',
            'user_id' => $this->giorgi->id, 'status' => 'active', 'last_activity_at' => now(),
        ]);
    }

    public function test_active_for_finds_pin_by_any_copy_and_by_listing_id(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);

        $this->assertTrue($pins->activeFor($this->listing(42, 5))?->is($pin));
        // Real Estate перестроил группы: у той же копии другой cluster.id — закрепление находится по id объявления.
        $this->assertTrue($pins->activeFor($this->listing(41, 9))?->is($pin));
        $this->assertNull($pins->activeFor($this->listing(77, 8)));
    }

    public function test_take_after_close_is_allowed(): void
    {
        $pins = app(Pins::class);
        $first = $pins->take($this->listing(41, 5), $this->nino);
        $pins->release($first, $this->nino);

        $second = $pins->take($this->listing(42, 5), $this->giorgi);

        $this->assertSame($this->giorgi->id, $second->user_id);
        $this->assertSame('closed', $first->fresh()->status);
        $this->assertSame('released', $first->fresh()->closed_reason);
    }

    public function test_take_other_direction_forbidden(): void
    {
        $this->expectException(CrmForbidden::class);
        app(Pins::class)->take($this->listing(13, null, 'rent'), $this->nino);
    }

    public function test_transfer_by_holder_and_by_lead_ok(): void
    {
        Event::fake([PinTransferred::class]);
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);

        $pins->transfer($pin, $this->giorgi, $this->nino);
        $this->assertSame($this->giorgi->id, $pin->fresh()->user_id);

        $pins->transfer($pin->fresh(), $this->nino, $this->lead);
        $this->assertSame($this->nino->id, $pin->fresh()->user_id);

        Event::assertDispatchedTimes(PinTransferred::class, 2);
        $this->assertSame(2, Activity::query()->where('event', Journal::PIN_TRANSFERRED)->count());
    }

    public function test_transfer_by_other_agent_forbidden(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);

        $this->expectException(CrmForbidden::class);
        $pins->transfer($pin, $this->giorgi, $this->giorgi);
    }

    public function test_holder_cannot_transfer_outside_own_group(): void
    {
        $otherLead = User::factory()->teamLead($this->company, Direction::Sale)->create();
        $stranger = User::factory()->agent($otherLead)->create();
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);

        $this->expectException(CrmForbidden::class);
        $pins->transfer($pin, $stranger, $this->nino);
    }

    public function test_transfer_to_user_without_direction_forbidden(): void
    {
        $rentLead = User::factory()->teamLead($this->company, Direction::Rent)->create();
        $owner = User::factory()->owner()->create();
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);

        $this->expectException(CrmForbidden::class);
        $pins->transfer($pin, $rentLead, $owner);
    }

    public function test_closed_pin_cannot_be_transferred(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);
        $pins->markUnavailable($pin, $this->nino);

        $this->expectException(CrmForbidden::class);
        $pins->transfer($pin->fresh(), $this->giorgi, $this->lead);
    }

    public function test_mark_unavailable_closes_and_dispatches_event(): void
    {
        Event::fake([PinClosed::class]);
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);

        $pins->markUnavailable($pin, $this->lead);

        $this->assertSame('closed', $pin->fresh()->status);
        $this->assertSame('unavailable', $pin->fresh()->closed_reason);
        Event::assertDispatched(PinClosed::class, fn (PinClosed $e): bool => $e->pin->is($pin) && $e->reason === 'unavailable');
        $this->assertSame(1, Activity::query()->where('event', Journal::PIN_UNAVAILABLE)->count());
    }

    public function test_other_agent_cannot_mark_unavailable_or_release(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);

        $this->expectException(CrmForbidden::class);
        $pins->markUnavailable($pin, $this->giorgi);
    }

    public function test_visible_to_follows_org_ladder(): void
    {
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(41, 5), $this->nino);
        $pins->take($this->listing(50), $this->giorgi);

        $this->assertSame([$ninoPin->id], ListingPin::query()->visibleTo($this->nino)->pluck('id')->all());
        $this->assertSame(2, ListingPin::query()->visibleTo($this->lead)->count());
        $this->assertSame(0, ListingPin::query()->visibleTo(User::factory()->companyOwner()->create())->count());
    }
}
