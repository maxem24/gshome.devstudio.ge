<?php

namespace Tests\Feature\Crm;

use App\Crm\Deals;
use App\Crm\Events\ObjectClosed;
use App\Crm\Events\PinClosed;
use App\Crm\Events\PinTransferred;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Exceptions\PinTaken;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Enums\DealStatus;
use App\Enums\Direction;
use App\Enums\UnavailableReason;
use App\Enums\UserStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\ListingObject;
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

    public function test_two_agents_take_same_listing(): void
    {
        $pins = app(Pins::class);
        $first = $pins->take($this->listing(41, 5), $this->nino);
        $second = $pins->take($this->listing(42, 5), $this->giorgi);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->object_id, $second->object_id);
        $this->assertSame(1, ListingObject::query()->count());
        $this->assertSame(2, ListingPin::query()->where('status', 'active')->count());
    }

    public function test_same_agent_cannot_take_twice(): void
    {
        $pins = app(Pins::class);
        $first = $pins->take($this->listing(41, 5), $this->nino);

        try {
            $pins->take($this->listing(42, 5), $this->nino);
            $this->fail('PinTaken expected');
        } catch (PinTaken $e) {
            $this->assertTrue($e->pin?->is($first));
        }
        $this->assertSame(1, ListingPin::query()->count());
    }

    public function test_same_agent_twice_fails_even_bypassing_service(): void
    {
        $first = app(Pins::class)->take($this->listing(41, 5), $this->nino);

        $this->expectException(UniqueConstraintViolationException::class);
        ListingPin::query()->create([
            'listing_id' => 42, 'cluster_id' => 5, 'pin_key' => 'c:5', 'direction' => 'sale', 'object_id' => $first->object_id,
            'user_id' => $this->nino->id, 'status' => 'active', 'last_activity_at' => now(),
        ]);
    }

    public function test_active_for_finds_oldest_pin_by_any_copy_and_by_listing_id(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);
        $pins->take($this->listing(41, 5), $this->giorgi);

        $this->assertTrue($pins->activeFor($this->listing(42, 5))?->is($pin));
        // Real Estate перестроил группы: у той же копии другой cluster.id — объект находится по id объявления.
        $this->assertTrue($pins->activeFor($this->listing(41, 9))?->is($pin));
        $this->assertNull($pins->activeFor($this->listing(77, 8)));
    }

    public function test_mine_and_active_of(): void
    {
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(41, 5), $this->nino);
        $giorgiPin = $pins->take($this->listing(41, 5), $this->giorgi);
        $object = $ninoPin->object;

        $this->assertTrue($pins->mine($object, $this->giorgi)?->is($giorgiPin));
        $this->assertNull($pins->mine($object, $this->lead));
        $this->assertSame([$ninoPin->id, $giorgiPin->id], $pins->activeOf($object)->pluck('id')->all());
        $this->assertTrue($pins->activeOf($object)->first()->relationLoaded('holder'));
    }

    public function test_leader_manages_oldest_visible_pin(): void
    {
        $pins = app(Pins::class);
        $stranger = User::factory()->agent(User::factory()->teamLead($this->company, Direction::Sale)->create())->create();
        $strangerPin = $pins->take($this->listing(41, 5), $stranger);
        $ninoPin = $pins->take($this->listing(41, 5), $this->nino);
        $giorgiPin = $pins->take($this->listing(41, 5), $this->giorgi);
        $object = $ninoPin->object;

        // Руководитель не ведёт объект сам: берётся самый старый из видимых ему, чужой группы пропускается.
        $this->assertTrue($pins->managed($object, $this->lead)?->is($ninoPin));
        // Свой пин важнее.
        $this->assertTrue($pins->managed($object, $this->giorgi)?->is($giorgiPin));
        $this->assertTrue($pins->managed($object, $stranger)?->is($strangerPin));
        $this->assertNull($pins->managed($object, User::factory()->agent(User::factory()->teamLead($this->company, Direction::Sale)->create())->create()));
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

    public function test_release_last_pin_abandons_object(): void
    {
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(41, 5), $this->nino);
        $giorgiPin = $pins->take($this->listing(41, 5), $this->giorgi);
        $object = $ninoPin->object;

        $pins->release($ninoPin, $this->nino);
        $this->assertTrue($object->fresh()->isOpen());

        $pins->release($giorgiPin, $this->giorgi);
        $this->assertSame('abandoned', $object->fresh()->closed_reason);
    }

    public function test_take_after_abandon_creates_new_object(): void
    {
        $pins = app(Pins::class);
        $first = $pins->take($this->listing(41, 5), $this->nino);
        $pins->release($first, $this->nino);

        $second = $pins->take($this->listing(41, 5), $this->nino);

        $this->assertNotSame($first->object_id, $second->object_id);
        $this->assertTrue($second->object->isOpen());
    }

    public function test_release_with_open_deals_forbidden(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);
        app(Deals::class)->open($pin, Client::factory()->create(), $this->nino);

        try {
            $pins->release($pin, $this->nino);
            $this->fail('CrmForbidden expected');
        } catch (CrmForbidden) {
            $this->assertSame('active', $pin->fresh()->status);
        }
    }

    public function test_owner_refused_closes_only_mine(): void
    {
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(41, 5), $this->nino);
        $giorgiPin = $pins->take($this->listing(41, 5), $this->giorgi);

        $pins->markUnavailable($ninoPin, UnavailableReason::OwnerRefused, $this->nino);

        $ninoPin->refresh();
        $this->assertSame('unavailable', $ninoPin->closed_reason);
        $this->assertSame(UnavailableReason::OwnerRefused, $ninoPin->unavailable_reason);
        $this->assertSame('active', $giorgiPin->fresh()->status);
        $this->assertTrue($giorgiPin->object->fresh()->isOpen());
    }

    public function test_owner_refused_by_last_agent_abandons_object(): void
    {
        $pin = app(Pins::class)->take($this->listing(41, 5), $this->nino);

        app(Pins::class)->markUnavailable($pin, UnavailableReason::Other, $this->nino);

        $this->assertSame('abandoned', $pin->object->fresh()->closed_reason);
    }

    public function test_fake_closes_object_for_all(): void
    {
        Event::fake([ObjectClosed::class]);
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(41, 5), $this->nino);
        $giorgiPin = $pins->take($this->listing(41, 5), $this->giorgi);
        $deal = app(Deals::class)->open($giorgiPin, Client::factory()->create(), $this->giorgi);

        $pins->markUnavailable($ninoPin, UnavailableReason::Fake, $this->nino);

        $object = $ninoPin->object->fresh();
        $this->assertSame('closed', $object->status);
        $this->assertSame('fake', $object->closed_reason);
        $this->assertSame($this->nino->id, $object->closed_by);
        $this->assertSame('unavailable', $ninoPin->fresh()->closed_reason);
        $this->assertSame('closed_by_other', $giorgiPin->fresh()->closed_reason);
        $this->assertSame(DealStatus::Unavailable, $deal->fresh()->status);
        $activity = Activity::query()->where('event', Journal::OBJECT_CLOSED)->sole();
        $this->assertSame([$giorgiPin->id], $activity->properties['closed_pins']);
        $this->assertSame('fake', $activity->properties['reason']);
        Event::assertDispatched(ObjectClosed::class, fn (ObjectClosed $e): bool => $e->object->is($object) && $e->closedPins->pluck('id')->all() === [$giorgiPin->id] && $e->by->is($this->nino));
    }

    public function test_rented_elsewhere_closes_object_with_its_reason(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);

        $pins->markUnavailable($pin, UnavailableReason::RentedElsewhere, $this->lead);

        $this->assertSame('rented_elsewhere', $pin->object->fresh()->closed_reason);
    }

    public function test_won_deal_closes_object_for_all(): void
    {
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(41, 5), $this->nino);
        $giorgiPin = $pins->take($this->listing(41, 5), $this->giorgi);
        $deal = app(Deals::class)->open($ninoPin, Client::factory()->create(), $this->nino);
        $other = app(Deals::class)->open($giorgiPin, Client::factory()->create(), $this->giorgi);

        app(Deals::class)->win($deal, '100', 'USD', $this->nino);

        $object = $ninoPin->object->fresh();
        $this->assertSame('won', $object->closed_reason);
        $this->assertSame($deal->id, $object->won_deal_id);
        $this->assertSame('won', $ninoPin->fresh()->closed_reason);
        $this->assertSame('closed_by_other', $giorgiPin->fresh()->closed_reason);
        $this->assertSame(DealStatus::Unavailable, $other->fresh()->status);
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
        $pins->markUnavailable($pin, UnavailableReason::Other, $this->nino);

        $this->expectException(CrmForbidden::class);
        $pins->transfer($pin->fresh(), $this->giorgi, $this->lead);
    }

    public function test_mark_unavailable_closes_and_dispatches_event(): void
    {
        Event::fake([PinClosed::class]);
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);

        $pins->markUnavailable($pin, UnavailableReason::Other, $this->lead);

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
        $pins->markUnavailable($pin, UnavailableReason::Other, $this->giorgi);
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

    public function test_transfer_to_agent_already_on_object_rejected(): void
    {
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(41, 5), $this->nino);
        $pins->take($this->listing(41, 5), $this->giorgi);

        try {
            $pins->transfer($ninoPin, $this->giorgi, $this->lead);
            $this->fail('CrmForbidden expected');
        } catch (CrmForbidden $e) {
            $this->assertSame('Recipient already works on this object.', $e->getMessage());
        }
        $this->assertSame($this->nino->id, $ninoPin->fresh()->user_id);
    }

    public function test_transfer_keeps_open_deals(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);
        $deal = app(Deals::class)->open($pin, Client::factory()->create(), $this->nino);

        $pins->transfer($pin, $this->giorgi, $this->lead);

        $this->assertSame(DealStatus::Open, $deal->fresh()->status);
        $this->assertSame($this->giorgi->id, $deal->fresh()->user_id);
    }

    public function test_lead_manages_pins_of_archived_agent(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);
        $this->nino->forceFill(['status' => UserStatus::Archived])->save();

        $pins->transfer($pin->fresh(), $this->giorgi, $this->lead);

        $this->assertSame($this->giorgi->id, $pin->fresh()->user_id);
    }

    public function test_stale_model_cannot_close_already_closed_pin(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);
        $stale = ListingPin::query()->findOrFail($pin->id);
        $pins->markUnavailable($pin, UnavailableReason::Other, $this->nino);

        $this->expectException(CrmForbidden::class);
        $pins->transfer($stale, $this->giorgi, $this->lead);
    }

    public function test_stale_model_cannot_act_after_holder_changed(): void
    {
        $pins = app(Pins::class);
        $pin = $pins->take($this->listing(41, 5), $this->nino);
        $stale = ListingPin::query()->findOrFail($pin->id);
        $pins->transfer($pin, $this->giorgi, $this->lead);

        $this->expectException(CrmForbidden::class);
        $pins->transfer($stale, $this->nino, $this->lead);
    }

    private function dealWithPartner(): array
    {
        $pin = app(Pins::class)->take($this->listing(61), $this->nino);
        $deals = app(Deals::class);
        $deal = $deals->open($pin, Client::factory()->create(), $this->nino);
        $partner = User::factory()->agent($this->lead)->create();
        $deals->setPartner($deal, $partner, $this->nino);

        return [$pin, $deal, $partner];
    }

    public function test_transfer_to_partner_removes_partner(): void
    {
        [$pin, $deal, $partner] = $this->dealWithPartner();

        app(Pins::class)->transfer($pin, $partner, $this->lead);

        $deal->refresh();
        $this->assertSame($partner->id, $deal->user_id);
        $this->assertNull($deal->partner_user_id);
        $this->assertSame($partner->id, $pin->fresh()->user_id);
        $removed = Activity::query()->where('event', Journal::DEAL_PARTNER_REMOVED)->first();
        $this->assertSame('became_holder', $removed->properties['reason']);
        $this->assertSame($this->lead->id, $removed->causer_id);
    }

    public function test_transfer_to_third_agent_keeps_partner(): void
    {
        [$pin, $deal, $partner] = $this->dealWithPartner();
        $third = User::factory()->agent($this->lead)->create();

        app(Pins::class)->transfer($pin, $third, $this->lead);

        $deal->refresh();
        $this->assertSame($third->id, $deal->user_id);
        $this->assertSame($partner->id, $deal->partner_user_id);
        $this->assertSame(0, Activity::query()->where('event', Journal::DEAL_PARTNER_REMOVED)->count());
    }
}
