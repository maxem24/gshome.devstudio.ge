<?php

namespace Tests\Feature\Filament;

use App\Crm\Deals;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Crm\Viewings;
use App\Enums\DealStatus;
use App\Enums\Direction;
use App\Enums\ViewingOutcome;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\ViewingsRelationManager;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\ListingPin;
use App\Models\User;
use App\Models\Viewing;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class DealResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        $this->lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
    }

    private function deal(User $agent, int $listingId): Deal
    {
        $listing = Listing::fromApi(RealEstateFake::listing([
            'id' => $listingId,
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');
        $pin = app(Pins::class)->take($listing, $agent);

        return app(Deals::class)->open($pin, Client::factory()->create(), $agent);
    }

    /** Скрытое действие вызвать нельзя: ждём исключения или тихого отказа, но не изменения данных. */
    private function tryCall(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable) {
        }
    }

    public function test_agent_lists_only_own_deals(): void
    {
        $nino = User::factory()->agent($this->lead)->create();
        $giorgi = User::factory()->agent($this->lead)->create();
        $mine = $this->deal($nino, 41);
        $theirs = $this->deal($giorgi, 42);

        $this->actingAs($nino);
        Livewire::test(ListDeals::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        // Тимлид видит обе; карточку чужой сделки агент не открывает.
        $this->actingAs($this->lead);
        Livewire::test(ListDeals::class)->assertCanSeeTableRecords([$mine, $theirs]);
        $this->actingAs($nino);
        $this->get(DealResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
        $this->get(DealResource::getUrl('view', ['record' => $mine]))->assertOk();
    }

    public function test_forged_deal_id_in_action_is_rejected(): void
    {
        $nino = User::factory()->agent($this->lead)->create();
        $giorgi = User::factory()->agent($this->lead)->create();
        $foreign = $this->deal($giorgi, 42);
        $viewing = app(Viewings::class)->schedule($foreign, now()->addDay(), null, $giorgi);
        $at = $viewing->scheduled_at->copy();
        $this->actingAs($nino);

        // Карточка чужой сделки: подставленный id не открывается вовсе.
        $this->get(DealResource::getUrl('view', ['record' => $foreign]))->assertNotFound();

        // Вкладка показов чужой сделки (подделанный ownerRecord): кнопки скрыты, вызов ничего не меняет.
        foreach ([
            ['schedule', null, ['at' => '2026-12-01 10:00']],
            ['reschedule', $viewing, ['at' => '2026-12-01 10:00']],
            ['outcome', $viewing, ['outcome' => ViewingOutcome::Failed->value]],
        ] as [$name, $record, $data]) {
            $this->tryCall(fn () => Livewire::test(ViewingsRelationManager::class, ['ownerRecord' => $foreign, 'pageClass' => ViewDeal::class])
                ->callTableAction($name, $record, $data));
        }

        $this->assertSame(1, Viewing::query()->count());
        $this->assertTrue($at->equalTo($viewing->refresh()->scheduled_at));
        $this->assertNull($viewing->outcome);
        $this->assertSame('call', $foreign->refresh()->stage);
        $this->assertSame(DealStatus::Open, $foreign->status);
    }

    public function test_forged_viewing_id_from_another_deal_is_rejected(): void
    {
        $nino = User::factory()->agent($this->lead)->create();
        $giorgi = User::factory()->agent($this->lead)->create();
        $mine = $this->deal($nino, 41);
        $foreign = $this->deal($giorgi, 42);
        $viewing = app(Viewings::class)->schedule($foreign, now()->addDay(), null, $giorgi);
        $this->actingAs($nino);

        // Своя вкладка, чужой показ: в таблице его нет — действие не должно ничего изменить.
        $this->tryCall(fn () => Livewire::test(ViewingsRelationManager::class, ['ownerRecord' => $mine, 'pageClass' => ViewDeal::class])
            ->callTableAction('outcome', $viewing, ['outcome' => ViewingOutcome::Failed->value]));

        $this->assertNull($viewing->refresh()->outcome);
    }

    public function test_win_flow_from_screen(): void
    {
        $nino = User::factory()->agent($this->lead)->create();
        $deal = $this->deal($nino, 41);
        $this->actingAs($nino);

        Livewire::test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('nextStage')
            ->assertNotified(__('gshome.crm.pins.closed'));
        $this->assertSame('viewing_scheduled', $deal->refresh()->stage);

        // Плохая сумма не проходит форму.
        Livewire::test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('win', ['amount' => '12abc', 'currency' => 'USD'])
            ->assertHasActionErrors(['amount']);
        $this->assertSame(DealStatus::Open, $deal->refresh()->status);

        Livewire::test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('win', ['amount' => '85000', 'currency' => 'USD'])
            ->assertHasNoActionErrors();

        $deal->refresh();
        $this->assertSame(DealStatus::Won, $deal->status);
        $this->assertSame('85000.00', $deal->amount);
        $this->assertSame('USD', $deal->currency);
        $this->assertSame(1, Activity::query()->where('event', Journal::DEAL_WON)->count());
    }

    public function test_lose_then_archive_closed_deal(): void
    {
        $nino = User::factory()->agent($this->lead)->create();
        $open = $this->deal($nino, 41);
        $this->actingAs($nino);

        // Открытую сделку агент в архив не убирает.
        Livewire::test(ViewDeal::class, ['record' => $open->id])->assertActionHidden('archive');

        Livewire::test(ViewDeal::class, ['record' => $open->id])
            ->callAction('lose', ['reason' => 'Купил другое'])
            ->assertHasNoActionErrors();
        $this->assertSame(DealStatus::Lost, $open->refresh()->status);
        $this->assertSame('Купил другое', $open->lost_reason);

        Livewire::test(ViewDeal::class, ['record' => $open->id])->callAction('archive');
        $this->assertTrue($open->refresh()->trashed());
        $this->assertSame(1, Activity::query()->where('event', Journal::ARCHIVED)->count());
    }

    public function test_agent_cannot_archive_open_deal_even_with_forged_call(): void
    {
        $nino = User::factory()->agent($this->lead)->create();
        $deal = $this->deal($nino, 41);
        $this->actingAs($nino);

        $this->tryCall(fn () => Livewire::test(ViewDeal::class, ['record' => $deal->id])->callAction('archive'));
        $this->assertFalse($deal->refresh()->trashed());
    }

    public function test_closed_deal_action_shows_danger_not_500(): void
    {
        $nino = User::factory()->agent($this->lead)->create();
        $deal = $this->deal($nino, 41);
        $this->actingAs($nino);

        $page = Livewire::test(ViewDeal::class, ['record' => $deal->id]);
        // Пока страница открыта, пин закрыли: сделка ещё открыта, но менять её уже нельзя.
        $deal->pin->update(['status' => ListingPin::CLOSED, 'closed_reason' => 'released', 'closed_at' => now()]);
        $page->callAction('lose', ['reason' => 'ещё раз'])->assertNotified(__('gshome.crm.deals.forbidden'));
        $this->assertSame(DealStatus::Open, $deal->refresh()->status);
    }

    public function test_viewing_schedule_and_outcome_from_screen(): void
    {
        $nino = User::factory()->agent($this->lead)->create();
        $deal = $this->deal($nino, 41);
        $this->actingAs($nino);
        $rm = fn () => Livewire::test(ViewingsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => ViewDeal::class]);

        $rm()->callTableAction('schedule', data: ['at' => '2026-12-01 15:00', 'notes' => 'у входа'])->assertHasNoActionErrors();
        $viewing = Viewing::query()->firstOrFail();
        // Ввод — по Тбилиси (UTC+4).
        $this->assertSame('2026-12-01 11:00', $viewing->scheduled_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('у входа', $viewing->notes);

        $rm()->callTableAction('reschedule', $viewing, ['at' => '2026-12-02 16:30'])->assertHasNoActionErrors();
        $this->assertSame('2026-12-02 12:30', $viewing->refresh()->scheduled_at->utc()->format('Y-m-d H:i'));

        $rm()->callTableAction('outcome', $viewing, ['outcome' => ViewingOutcome::Confirmed->value])->assertHasNoActionErrors();
        $this->assertSame(ViewingOutcome::Confirmed, $viewing->refresh()->outcome);

        foreach ([Journal::VIEWING_SCHEDULED, Journal::VIEWING_RESCHEDULED, Journal::VIEWING_OUTCOME] as $event) {
            $this->assertSame(1, Activity::query()->where('event', $event)->count(), $event);
        }
    }

    public function test_lead_manages_agents_deal_and_filters_work(): void
    {
        $nino = User::factory()->agent($this->lead)->create();
        $deal = $this->deal($nino, 41);
        $this->actingAs($this->lead);

        Livewire::test(ListDeals::class)
            ->filterTable('user_id', $nino->id)
            ->assertCanSeeTableRecords([$deal])
            ->filterTable('status', 'won')
            ->assertCanNotSeeTableRecords([$deal]);

        Livewire::test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('lose', ['reason' => 'нет ответа'])
            ->assertHasNoActionErrors();
        $this->assertSame(DealStatus::Lost, $deal->refresh()->status);
    }
}
