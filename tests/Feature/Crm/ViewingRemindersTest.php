<?php

namespace Tests\Feature\Crm;

use App\Crm\Deals;
use App\Crm\Pins;
use App\Enums\Direction;
use App\Enums\ViewingOutcome;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\User;
use App\Models\Viewing;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ViewingRemindersTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $nino;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
        $this->nino = User::factory()->agent($this->lead)->create();
        $listing = Listing::fromApi(RealEstateFake::listing([
            'id' => 41,
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');
        $pin = app(Pins::class)->take($listing, $this->nino);
        $this->deal = app(Deals::class)->open($pin, Client::factory()->create(), $this->nino);
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Tbilisi');
    }

    private function viewing(string $time): Viewing
    {
        $viewing = new Viewing(['deal_id' => $this->deal->id, 'scheduled_at' => $this->at($time)]);
        $viewing->save();

        return $viewing;
    }

    private function tick(string $now): void
    {
        Carbon::setTestNow($this->at($now));
        $this->artisan('crm:viewing-reminders')->assertSuccessful();
    }

    private function sent(User $user, string $kind): int
    {
        return $user->notifications()->get()->filter(
            fn ($n) => str_contains($n->data['title'], match ($kind) {
                'before' => 'Скоро',
                'day_of' => 'Сегодня',
                default => 'итог не внесён',
            })
        )->count();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_before_sent_once_two_hours_ahead(): void
    {
        $this->viewing('2026-11-02 14:00');

        $this->tick('2026-11-02 11:50');
        $this->assertSame(0, $this->sent($this->nino, 'before'));

        $this->tick('2026-11-02 12:05');
        $this->tick('2026-11-02 12:10');
        $this->tick('2026-11-02 13:30');

        $this->assertSame(1, $this->sent($this->nino, 'before'));
        $title = $this->nino->notifications()->latest('created_at')->get()
            ->first(fn ($n) => str_contains($n->data['title'], 'Скоро'))->data['title'];
        $this->assertStringContainsString('14:00', $title);
        $this->assertStringContainsString('41', $title);
    }

    public function test_day_of_sent_at_nine_once(): void
    {
        $this->viewing('2026-11-02 16:00');

        $this->tick('2026-11-02 08:55');
        $this->assertSame(0, $this->sent($this->nino, 'day_of'));

        $this->tick('2026-11-02 09:00');
        $this->tick('2026-11-02 09:05');
        $this->tick('2026-11-02 10:00');

        $this->assertSame(1, $this->sent($this->nino, 'day_of'));
    }

    public function test_day_of_skipped_for_early_viewing_and_other_days(): void
    {
        $this->viewing('2026-11-02 08:00');
        $this->viewing('2026-11-03 16:00');

        $this->tick('2026-11-02 07:00');

        $this->assertSame(0, $this->sent($this->nino, 'day_of'));
        // Первый показ в окне «за 2 часа» — это единственное, что должно прийти.
        $this->assertSame(1, $this->nino->notifications()->count());
    }

    public function test_overdue_after_one_hour_then_daily_until_outcome(): void
    {
        $viewing = $this->viewing('2026-11-02 10:00');

        $this->tick('2026-11-02 10:55');
        $this->assertSame(0, $this->sent($this->nino, 'overdue'));

        $this->tick('2026-11-02 11:00');
        $this->tick('2026-11-02 11:05');
        $this->assertSame(1, $this->sent($this->nino, 'overdue'));

        $this->tick('2026-11-03 09:00');
        $this->tick('2026-11-03 09:05');
        $this->assertSame(2, $this->sent($this->nino, 'overdue'));

        $viewing->update(['outcome' => ViewingOutcome::FollowUp, 'outcome_at' => now()]);
        $this->tick('2026-11-04 09:00');
        $this->assertSame(2, $this->sent($this->nino, 'overdue'));
    }

    public function test_no_reminders_for_closed_or_archived(): void
    {
        $viewing = $this->viewing('2026-11-02 14:00');
        $this->tick('2026-11-02 12:30');
        $this->assertSame(1, $this->nino->notifications()->count());

        $viewing->delete();
        $this->tick('2026-11-02 16:00');
        $this->assertSame(1, $this->nino->notifications()->count(), 'архивный показ');

        $viewing->restore();
        $this->deal->delete();
        $this->tick('2026-11-02 16:00');
        $this->assertSame(1, $this->nino->notifications()->count(), 'архивная сделка');

        $this->deal->restore();
        $this->deal->update(['status' => 'won']);
        $this->tick('2026-11-02 16:00');
        $this->assertSame(1, $this->nino->notifications()->count(), 'закрытая сделка');
    }

    public function test_blocked_agent_gets_nothing_and_no_dedupe_row(): void
    {
        $this->viewing('2026-11-02 14:00');
        $this->nino->update(['status' => 'blocked']);

        $this->tick('2026-11-02 12:30');

        $this->assertSame(0, $this->nino->notifications()->count());
        $this->assertDatabaseCount('viewing_reminders', 0);
    }

    public function test_recipient_is_deal_agent_after_transfer(): void
    {
        $this->viewing('2026-11-02 14:00');
        $giorgi = User::factory()->agent($this->lead)->create();
        $this->deal->update(['user_id' => $giorgi->id]);

        $this->tick('2026-11-02 12:30');

        $this->assertSame(0, $this->nino->notifications()->count());
        $this->assertSame(1, $giorgi->notifications()->count());
    }
}
