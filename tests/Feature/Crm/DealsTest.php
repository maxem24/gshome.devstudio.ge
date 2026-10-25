<?php

namespace Tests\Feature\Crm;

use App\Crm\Deals;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Enums\DealStatus;
use App\Enums\Direction;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\ListingPin;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class DealsTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $nino;

    private User $giorgi;

    private ListingPin $pin;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::factory()->create();
        $this->lead = User::factory()->teamLead($company, Direction::Sale)->create();
        $this->nino = User::factory()->agent($this->lead)->create();
        $this->giorgi = User::factory()->agent($this->lead)->create();
        $this->pin = $this->takePin(41);
    }

    private function takePin(int $id): ListingPin
    {
        $listing = Listing::fromApi(RealEstateFake::listing([
            'id' => $id,
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');

        return app(Pins::class)->take($listing, $this->nino);
    }

    private function client(): Client
    {
        return Client::factory()->create();
    }

    public function test_open_sets_first_stage_and_holder_as_agent(): void
    {
        $deal = app(Deals::class)->open($this->pin, $this->client(), $this->nino);

        $this->assertSame('call', $deal->stage);
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertSame($this->nino->id, $deal->user_id);
        $this->assertSame(1, $deal->stageChanges()->whereNull('from_stage')->where('to_stage', 'call')->count());
        $this->assertSame(1, Activity::query()->where('event', Journal::DEAL_OPENED)->count());
        $this->assertNotNull($this->pin->fresh()->last_activity_at);
    }

    public function test_open_by_lead_makes_holder_the_agent(): void
    {
        $deal = app(Deals::class)->open($this->pin, $this->client(), $this->lead);

        $this->assertSame($this->nino->id, $deal->user_id);
    }

    public function test_open_by_other_agent_forbidden(): void
    {
        $this->expectException(CrmForbidden::class);

        app(Deals::class)->open($this->pin, $this->client(), $this->giorgi);
    }

    public function test_two_deals_on_one_pin_for_two_clients(): void
    {
        $deals = app(Deals::class);
        $deals->open($this->pin, $this->client(), $this->nino);
        $deals->open($this->pin, $this->client(), $this->nino);

        $this->assertSame(2, $this->pin->deals()->count());
    }

    public function test_same_client_twice_on_pin_rejected(): void
    {
        $client = $this->client();
        $deals = app(Deals::class);
        $deals->open($this->pin, $client, $this->nino);

        $this->expectException(CrmForbidden::class);
        $deals->open($this->pin, $client, $this->nino);
    }

    public function test_move_stage_records_change_with_time(): void
    {
        $deal = app(Deals::class)->open($this->pin, $this->client(), $this->nino);
        $this->travelTo(now()->addHour()->startOfSecond());

        app(Deals::class)->moveStage($deal, 'viewing_scheduled', $this->nino);

        $change = $deal->stageChanges()->where('to_stage', 'viewing_scheduled')->first();
        $this->assertSame('call', $change->from_stage);
        $this->assertSame($this->nino->id, $change->changed_by);
        $this->assertTrue($change->changed_at->format('Y-m-d H:i:s') === now()->format('Y-m-d H:i:s'));
        $this->assertSame('viewing_scheduled', $deal->fresh()->stage);
        $this->assertSame(1, Activity::query()->where('event', Journal::DEAL_STAGE_CHANGED)->count());
    }

    public function test_move_stage_rejects_terminal_and_unknown_stage(): void
    {
        $deal = app(Deals::class)->open($this->pin, $this->client(), $this->nino);

        foreach (['won', 'lost', 'nope'] as $key) {
            try {
                app(Deals::class)->moveStage($deal, $key, $this->nino);
                $this->fail("Stage {$key} must be rejected.");
            } catch (CrmForbidden) {
                $this->assertSame('call', $deal->fresh()->stage);
            }
        }
    }

    public function test_win_closes_pin_and_other_deals_unavailable(): void
    {
        $deals = app(Deals::class);
        $won = $deals->open($this->pin, $this->client(), $this->nino);
        $other = $deals->open($this->pin, $this->client(), $this->nino);

        $deals->win($won, '120000.50', 'USD', $this->nino);

        $won->refresh();
        $this->assertSame(DealStatus::Won, $won->status);
        $this->assertSame('won', $won->stage);
        $this->assertSame('120000.50', $won->amount);
        $this->assertSame('USD', $won->currency);
        $this->assertNotNull($won->closed_at);
        $this->assertSame(DealStatus::Unavailable, $other->fresh()->status);
        $this->assertSame('closed', $this->pin->fresh()->status);
        $this->assertSame('won', $this->pin->fresh()->closed_reason);
        $this->assertSame(1, Activity::query()->where('event', Journal::DEAL_WON)->count());
        $this->assertSame(1, Activity::query()->where('event', Journal::PIN_WON)->count());
    }

    public function test_win_rejects_unknown_currency(): void
    {
        $deal = app(Deals::class)->open($this->pin, $this->client(), $this->nino);

        try {
            app(Deals::class)->win($deal, '1000', 'EUR', $this->nino);
            $this->fail('Currency must be rejected.');
        } catch (CrmForbidden) {
            $this->assertSame(DealStatus::Open, $deal->fresh()->status);
            $this->assertTrue($this->pin->fresh()->isActive());
        }
    }

    public function test_win_rejects_bad_amount(): void
    {
        $deal = app(Deals::class)->open($this->pin, $this->client(), $this->nino);

        foreach (['0', '-5', 'abc', ''] as $amount) {
            try {
                app(Deals::class)->win($deal, $amount, 'USD', $this->nino);
                $this->fail("Amount '{$amount}' must be rejected.");
            } catch (CrmForbidden) {
                $this->assertSame(DealStatus::Open, $deal->fresh()->status);
            }
        }
    }

    public function test_lose_records_reason_and_keeps_pin_active(): void
    {
        $deal = app(Deals::class)->open($this->pin, $this->client(), $this->nino);

        app(Deals::class)->lose($deal, 'Нашёл другой вариант', $this->nino);

        $deal->refresh();
        $this->assertSame(DealStatus::Lost, $deal->status);
        $this->assertSame('lost', $deal->stage);
        $this->assertSame('Нашёл другой вариант', $deal->lost_reason);
        $this->assertTrue($this->pin->fresh()->isActive());
        $this->assertSame(1, Activity::query()->where('event', Journal::DEAL_LOST)->count());
    }

    public function test_closed_deal_cannot_change(): void
    {
        $deals = app(Deals::class);
        $deal = $deals->open($this->pin, $this->client(), $this->nino);
        $deals->lose($deal, 'reason', $this->nino);

        foreach ([
            fn () => $deals->moveStage($deal, 'negotiation', $this->nino),
            fn () => $deals->lose($deal, 'again', $this->nino),
            fn () => $deals->win($deal, '10', 'USD', $this->nino),
        ] as $action) {
            try {
                $action();
                $this->fail('Closed deal must not change.');
            } catch (CrmForbidden) {
                $this->assertSame(DealStatus::Lost, $deal->fresh()->status);
            }
        }
        $this->assertTrue($this->pin->fresh()->isActive());
    }

    public function test_other_agent_cannot_change_deal(): void
    {
        $deal = app(Deals::class)->open($this->pin, $this->client(), $this->nino);

        $this->assertFalse(app(Deals::class)->canManage($deal, $this->giorgi));
        $this->assertTrue(app(Deals::class)->canManage($deal, $this->lead));
        $this->expectException(CrmForbidden::class);
        app(Deals::class)->moveStage($deal, 'negotiation', $this->giorgi);
    }

    public function test_mark_unavailable_closes_open_deals(): void
    {
        $deals = app(Deals::class);
        $a = $deals->open($this->pin, $this->client(), $this->nino);
        $b = $deals->open($this->pin, $this->client(), $this->nino);
        $lost = $deals->open($this->pin, $this->client(), $this->nino);
        $deals->lose($lost, 'x', $this->nino);

        app(Pins::class)->markUnavailable($this->pin, $this->nino);

        $this->assertSame(DealStatus::Unavailable, $a->fresh()->status);
        $this->assertSame(DealStatus::Unavailable, $b->fresh()->status);
        $this->assertSame(DealStatus::Lost, $lost->fresh()->status);
    }

    public function test_transfer_moves_open_deals_to_new_holder(): void
    {
        $deals = app(Deals::class);
        $open = $deals->open($this->pin, $this->client(), $this->nino);
        $lost = $deals->open($this->pin, $this->client(), $this->nino);
        $deals->lose($lost, 'x', $this->nino);

        app(Pins::class)->transfer($this->pin, $this->giorgi, $this->lead);

        $this->assertSame($this->giorgi->id, $open->fresh()->user_id);
        $this->assertSame($this->nino->id, $lost->fresh()->user_id);
        $this->assertTrue($deals->canManage($open->fresh(), $this->giorgi));
        $this->assertFalse($deals->canManage($open->fresh(), $this->nino));
    }

    public function test_release_with_open_deal_forbidden(): void
    {
        $deal = app(Deals::class)->open($this->pin, $this->client(), $this->nino);

        try {
            app(Pins::class)->release($this->pin, $this->nino);
            $this->fail('Release must be forbidden.');
        } catch (CrmForbidden) {
            $this->assertTrue($this->pin->fresh()->isActive());
        }

        app(Deals::class)->lose($deal, 'x', $this->nino);
        app(Pins::class)->release($this->pin, $this->nino);
        $this->assertSame('released', $this->pin->fresh()->closed_reason);
    }

    public function test_deal_cannot_be_opened_on_closed_pin(): void
    {
        app(Pins::class)->release($this->pin, $this->nino);

        $this->expectException(CrmForbidden::class);
        app(Deals::class)->open($this->pin->fresh(), $this->client(), $this->nino);
    }

    public function test_journal_has_no_client_name_or_phone(): void
    {
        $client = $this->client();
        $deal = app(Deals::class)->open($this->pin, $client, $this->nino);
        app(Deals::class)->win($deal, '100', 'GEL', $this->nino);

        $dump = Activity::query()->get()->map(fn ($a) => json_encode($a->properties))->implode('|');
        $this->assertStringNotContainsString($client->phone_e164, $dump);
        $this->assertStringNotContainsString($client->first_name, $dump);
        $this->assertGreaterThan(0, Deal::query()->count());
    }
}
