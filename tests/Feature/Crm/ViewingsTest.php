<?php

namespace Tests\Feature\Crm;

use App\Crm\Deals;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Crm\Viewings;
use App\Enums\Direction;
use App\Enums\ViewingOutcome;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ViewingsTest extends TestCase
{
    use RefreshDatabase;

    private User $nino;

    private User $giorgi;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
        $this->nino = User::factory()->agent($lead)->create();
        $this->giorgi = User::factory()->agent($lead)->create();
        $listing = Listing::fromApi(RealEstateFake::listing([
            'id' => 41,
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');
        $pin = app(Pins::class)->take($listing, $this->nino);
        $this->deal = app(Deals::class)->open($pin, Client::factory()->create(), $this->nino);
    }

    public function test_schedule_reschedule_outcome_journal_has_three_entries_with_times(): void
    {
        $viewings = app(Viewings::class);
        $first = now()->addDay()->startOfHour();
        $second = $first->copy()->addDay();

        $viewing = $viewings->schedule($this->deal, $first, 'Ключи у консьержа', $this->nino);
        $viewings->reschedule($viewing, $second, $this->nino);
        $viewings->recordOutcome($viewing, ViewingOutcome::FollowUp, $this->nino);

        $viewing->refresh();
        // Сравниваем стенные часы: сессия Postgres в UTC, приложение в Asia/Tbilisi (см. отчёт).
        $this->assertSame($second->format('Y-m-d H:i:s'), $viewing->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(ViewingOutcome::FollowUp, $viewing->outcome);
        $this->assertNotNull($viewing->outcome_at);
        $this->assertSame('Ключи у консьержа', $viewing->notes);

        $events = Activity::query()->whereIn('event', [Journal::VIEWING_SCHEDULED, Journal::VIEWING_RESCHEDULED, Journal::VIEWING_OUTCOME])->orderBy('id')->get();
        $this->assertSame([Journal::VIEWING_SCHEDULED, Journal::VIEWING_RESCHEDULED, Journal::VIEWING_OUTCOME], $events->pluck('event')->all());
        $this->assertSame($first->toIso8601String(), $events[0]->properties['new_at']);
        $this->assertSame($first->toIso8601String(), $events[1]->properties['old_at']);
        $this->assertSame($second->toIso8601String(), $events[1]->properties['new_at']);
        $this->assertSame('follow_up', $events[2]->properties['outcome']);
        $this->assertNotNull($events[2]->properties['at']);
    }

    public function test_viewing_of_other_agent_forbidden(): void
    {
        $viewing = app(Viewings::class)->schedule($this->deal, now()->addDay(), null, $this->nino);

        foreach ([
            fn () => app(Viewings::class)->schedule($this->deal, now()->addDay(), null, $this->giorgi),
            fn () => app(Viewings::class)->reschedule($viewing, now()->addDays(2), $this->giorgi),
            fn () => app(Viewings::class)->recordOutcome($viewing, ViewingOutcome::Failed, $this->giorgi),
        ] as $action) {
            try {
                $action();
                $this->fail('Other agent must be forbidden.');
            } catch (CrmForbidden) {
                $this->assertSame(1, $this->deal->viewings()->count());
                $this->assertNull($viewing->fresh()->outcome);
            }
        }
    }

    public function test_viewing_of_closed_deal_cannot_be_scheduled(): void
    {
        app(Deals::class)->lose($this->deal, 'x', $this->nino);

        $this->expectException(CrmForbidden::class);
        app(Viewings::class)->schedule($this->deal->fresh(), now()->addDay(), null, $this->nino);
    }

    public function test_every_action_touches_pin(): void
    {
        $this->travelTo(now()->addDays(3)->startOfSecond());

        app(Viewings::class)->schedule($this->deal, now()->addDay(), null, $this->nino);

        $this->assertTrue($this->deal->pin->fresh()->last_activity_at->format('Y-m-d H:i:s') === now()->format('Y-m-d H:i:s'));
    }

    public function test_stale_deal_after_win_cannot_get_viewing(): void
    {
        $stale = Deal::query()->findOrFail($this->deal->id);
        app(Deals::class)->win($this->deal, '100', 'USD', $this->nino);

        try {
            app(Viewings::class)->schedule($stale, now()->addDay(), null, $this->nino);
            $this->fail('Closed deal must be rejected.');
        } catch (CrmForbidden) {
            $this->assertSame(0, $this->deal->viewings()->count());
        }
    }

    public function test_reschedule_and_outcome_on_closed_deal_rejected(): void
    {
        $viewing = app(Viewings::class)->schedule($this->deal, now()->addDay()->startOfHour(), null, $this->nino);
        $before = $viewing->fresh()->scheduled_at->toIso8601String();
        app(Deals::class)->lose($this->deal, 'x', $this->nino);
        $viewing = $viewing->fresh();

        foreach ([
            fn () => app(Viewings::class)->reschedule($viewing, now()->addDays(5), $this->nino),
            fn () => app(Viewings::class)->recordOutcome($viewing, ViewingOutcome::Failed, $this->nino),
        ] as $action) {
            try {
                $action();
                $this->fail('Closed deal must be rejected.');
            } catch (CrmForbidden) {
                $fresh = $viewing->fresh();
                $this->assertSame($before, $fresh->scheduled_at->toIso8601String());
                $this->assertNull($fresh->outcome);
            }
        }
    }
}
