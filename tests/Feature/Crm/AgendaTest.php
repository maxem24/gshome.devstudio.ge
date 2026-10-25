<?php

namespace Tests\Feature\Crm;

use App\Crm\Agenda;
use App\Crm\Deals;
use App\Crm\Pins;
use App\Crm\Tasks;
use App\Crm\Viewings;
use App\Enums\Direction;
use App\Enums\ViewingOutcome;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    use RefreshDatabase;

    private User $nino;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
        $this->nino = User::factory()->agent($lead)->create();
        $listing = Listing::fromApi(RealEstateFake::listing([
            'id' => 41,
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');
        $pin = app(Pins::class)->take($listing, $this->nino);
        $this->deal = app(Deals::class)->open($pin, Client::factory()->create(), $this->nino);
    }

    public function test_mixes_open_tasks_and_viewings_without_outcome(): void
    {
        $tasks = app(Tasks::class);
        $viewings = app(Viewings::class);

        $noDue = $tasks->create($this->nino, $this->nino, 'Без срока', null);
        $later = $tasks->create($this->nino, $this->nino, 'Позже', now()->addDays(2));
        $done = $tasks->create($this->nino, $this->nino, 'Сделано', now()->addHour());
        $tasks->setStatus($done, 'done', $this->nino);
        $overdueViewing = $viewings->schedule($this->deal, now()->subHour(), null, $this->nino);
        $soon = $viewings->schedule($this->deal, now()->addDay(), 'Ключи у консьержа', $this->nino);
        $finished = $viewings->schedule($this->deal, now()->addHours(5), null, $this->nino);
        $viewings->recordOutcome($finished, ViewingOutcome::Confirmed, $this->nino);

        $items = app(Agenda::class)->for($this->nino);

        $this->assertSame(
            [['viewing', $overdueViewing->id], ['viewing', $soon->id], ['task', $later->id], ['task', $noDue->id]],
            $items->map(fn ($i) => [$i->type, $i->id])->all(),
        );
        $this->assertTrue($items[0]->overdue);
        $this->assertFalse($items[1]->overdue);
        $this->assertNull($items[3]->at);
        $this->assertFalse($items[3]->overdue);
        $this->assertStringContainsString('Ключи у консьержа', $items[1]->title);
    }

    public function test_other_agents_and_closed_deals_are_excluded(): void
    {
        $viewings = app(Viewings::class);
        $viewings->schedule($this->deal, now()->addDay(), null, $this->nino);
        $stranger = User::factory()->agent($this->nino->teamLead)->create();

        $this->assertCount(0, app(Agenda::class)->for($stranger));

        $this->deal->update(['status' => 'lost']);
        $this->assertCount(0, app(Agenda::class)->for($this->nino));
    }
}
