<?php

namespace Tests\Feature\Crm;

use App\Crm\AgentReport;
use App\Crm\AgentReportRow;
use App\Enums\DealStatus;
use App\Enums\Direction;
use App\Filament\Pages\AgentReportPage;
use App\Filament\Pages\ListingView;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\ListingPin;
use App\Models\User;
use App\Models\Viewing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentReportTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $nino;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
        $this->lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
        $this->nino = User::factory()->agent($this->lead)->create();
    }

    private function pin(User $u, string $lastActivity = 'now', string $status = 'active'): ListingPin
    {
        $this->seq++;

        return ListingPin::query()->create([
            'listing_id' => 100 + $this->seq, 'pin_key' => 'l'.(100 + $this->seq), 'direction' => Direction::Sale,
            'user_id' => $u->id, 'status' => $status, 'closed_reason' => $status === 'active' ? null : 'manual', 'last_activity_at' => $lastActivity === 'now' ? now() : now()->sub($lastActivity),
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function deal(User $u, array $attrs = []): Deal
    {
        $deal = Deal::query()->create($attrs + [
            'listing_pin_id' => $this->pin($u)->id, 'client_id' => Client::factory()->create()->id,
            'user_id' => $u->id, 'stage' => 'call', 'status' => DealStatus::Open,
        ]);
        if (isset($attrs['created_at'])) {
            $deal->forceFill(['created_at' => $attrs['created_at']])->saveQuietly();
        }

        return $deal;
    }

    private function row(User $viewer, User $of): AgentReportRow
    {
        $rows = (new AgentReport)->rows($viewer, now()->subDays(30), now());

        return collect($rows)->first(fn (AgentReportRow $r): bool => $r->user->is($of));
    }

    public function test_counts_and_unavailable_not_a_loss(): void
    {
        $this->deal($this->nino);
        $this->deal($this->nino, ['status' => DealStatus::Won, 'closed_at' => now()->subDay(), 'amount' => 100, 'currency' => 'USD']);
        $this->deal($this->nino, ['status' => DealStatus::Lost, 'closed_at' => now()->subDay()]);
        $this->deal($this->nino, ['status' => DealStatus::Unavailable, 'closed_at' => now()->subDay()]);
        $this->deal($this->nino, ['status' => DealStatus::Lost, 'closed_at' => now()->subDays(60), 'created_at' => now()->subDays(61)]);
        $archived = $this->deal($this->nino);
        $archived->delete();
        $d = $this->deal($this->nino);
        Viewing::query()->create(['deal_id' => $d->id, 'scheduled_at' => now()->subDay(), 'outcome' => 'confirmed', 'outcome_at' => now()->subDay()]);
        Viewing::query()->create(['deal_id' => $d->id, 'scheduled_at' => now()->addDay()]);

        $row = $this->row($this->lead, $this->nino);

        $this->assertSame(1, $row->dealsWon);
        $this->assertSame(1, $row->dealsLost);
        $this->assertSame(5, $row->dealsOpened);
        $this->assertSame(1, $row->viewingsHeld);
        $this->assertSame(7, $row->pinsActive);
    }

    public function test_earnings_grouped_by_currency(): void
    {
        foreach ([['USD', 100], ['USD', 50], ['GEL', 300]] as [$cur, $amount]) {
            $this->deal($this->nino, ['status' => DealStatus::Won, 'closed_at' => now()->subDay(), 'amount' => $amount, 'currency' => $cur]);
        }
        $this->deal($this->nino, ['status' => DealStatus::Lost, 'closed_at' => now()->subDay(), 'amount' => 999, 'currency' => 'USD']);

        $earn = $this->row($this->lead, $this->nino)->earnings;

        $this->assertEquals(['USD' => 150, 'GEL' => 300], array_map('floatval', $earn));
    }

    public function test_avg_time_per_stage(): void
    {
        $a = $this->deal($this->nino, ['stage' => 'viewing_scheduled']);
        $b = $this->deal($this->nino, ['stage' => 'call']);
        $change = fn (Deal $d, ?string $from, string $to, string $ago) => DealStageChange::query()->create([
            'deal_id' => $d->id, 'from_stage' => $from, 'to_stage' => $to, 'changed_by' => $this->nino->id, 'changed_at' => now()->sub($ago),
        ]);
        // сделка A: call 1 час, затем viewing_scheduled до сих пор (2 часа назад → now)
        $change($a, null, 'call', '3 hours');
        $change($a, 'call', 'viewing_scheduled', '2 hours');
        // сделка B: call 3 часа
        $change($b, null, 'call', '5 hours');
        $change($b, 'call', 'viewing_scheduled', '2 hours');

        $avg = $this->row($this->lead, $this->nino)->avgStageSeconds;

        $this->assertSame(7200, $avg['call']);          // (3600 + 10800) / 2
        $this->assertSame(7200, $avg['viewing_scheduled']); // оба ещё на этапе: 2 часа
    }

    public function test_stale_after_seven_days_not_six(): void
    {
        $this->pin($this->nino, '6 days');
        $old = $this->pin($this->nino, '8 days');
        $this->pin($this->nino, '30 days', 'closed');

        $row = $this->row($this->lead, $this->nino);

        $this->assertSame(1, $row->stalePins);
        $this->assertSame([$old->id], $row->stalePinIds);
        $this->assertSame(2, $row->pinsActive);
    }

    public function test_agent_sees_only_own_row(): void
    {
        $other = User::factory()->agent($this->lead)->create();

        $names = collect((new AgentReport)->rows($this->nino, now()->subDays(30), now()))->map(fn ($r) => $r->user->id)->all();

        $this->assertSame([$this->nino->id], $names);
        $this->assertContains($other->id, collect((new AgentReport)->rows($this->lead, now()->subDays(30), now()))->map(fn ($r) => $r->user->id)->all());
    }

    public function test_page_lists_stale_pins_and_forbids_foreign_user(): void
    {
        $old = $this->pin($this->nino, '9 days');
        $stranger = User::factory()->agent(User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create())->create();

        $this->actingAs($this->lead);
        Livewire::test(AgentReportPage::class)
            ->assertSee($this->nino->name)
            ->call('showStale', $this->nino->id)
            ->assertSee(ListingView::getUrl(['listing' => $old->listing_id]))
            ->call('showStale', $stranger->id)
            ->assertForbidden();
    }

    public function test_closed_deal_last_stage_does_not_keep_growing_and_won_lost_are_excluded(): void
    {
        $d = $this->deal($this->nino, ['stage' => 'won', 'status' => DealStatus::Won, 'closed_at' => now()->subDays(10)]);
        $change = fn (?string $from, string $to, string $ago) => DealStageChange::query()->create([
            'deal_id' => $d->id, 'from_stage' => $from, 'to_stage' => $to, 'changed_by' => $this->nino->id, 'changed_at' => now()->sub($ago),
        ]);
        $change(null, 'call', '12 days');
        $change('call', 'negotiation', '11 days');
        $change('negotiation', 'won', '10 days');
        $d->forceFill(['closed_at' => now()->subDays(10)])->saveQuietly();

        $avg = $this->row($this->lead, $this->nino)->avgStageSeconds;

        $this->assertSame(86400, $avg['call']);
        // «Переговоры» кончились закрытием сделки (за день), а не растут до «сейчас».
        $this->assertSame(86400, $avg['negotiation']);
        $this->assertArrayNotHasKey('won', $avg);
    }
}
