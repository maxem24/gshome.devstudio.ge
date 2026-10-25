<?php

namespace Tests\Feature\Crm;

use App\Crm\Deals;
use App\Crm\Journal;
use App\Crm\JournalQuery;
use App\Crm\Pins;
use App\Enums\Direction;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class JournalQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_sees_own_agents_entries_only(): void
    {
        $company = Company::factory()->create();
        $lead = User::factory()->teamLead($company)->create();
        $mine = User::factory()->agent($lead)->create();
        $otherLead = User::factory()->teamLead($company)->create();
        $foreign = User::factory()->agent($otherLead)->create();
        Journal::record(Journal::PIN_TAKEN, null, ['listing_id' => 1], $mine);
        Journal::record(Journal::PIN_TAKEN, null, ['listing_id' => 2], $lead);
        Journal::record(Journal::PIN_TAKEN, null, ['listing_id' => 3], $foreign);

        $ids = JournalQuery::visibleTo($lead)->pluck('properties')->map(fn ($p) => $p['listing_id'])->sort()->values()->all();

        $this->assertSame([1, 2], $ids);
    }

    public function test_agent_gets_empty_journal(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        Journal::record(Journal::PIN_TAKEN, null, ['listing_id' => 1], $agent);

        $this->assertSame(0, JournalQuery::visibleTo($agent)->count());
    }

    public function test_feed_scoped_to_company_and_without_client_name(): void
    {
        RealEstateFake::configure();
        $company = Company::factory()->create();
        $lead = User::factory()->teamLead($company, Direction::Sale)->create(['name' => 'Лейла']);
        $agent = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $stranger = User::factory()->teamLead(Company::factory()->create())->create(['name' => 'Чужой']);

        $listing = Listing::fromApi(RealEstateFake::listing(['id' => 41, 'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale']]), 'ru');
        $pin = app(Pins::class)->take($listing, $agent);
        app(Deals::class)->open($pin, Client::factory()->create(['first_name' => 'Нино', 'last_name' => 'Беридзе']), $agent);
        Journal::record(Journal::PIN_TAKEN, null, ['listing_id' => 99], $stranger);
        Journal::record(Journal::LISTING_VIEWED, null, ['listing_id' => 41], $agent);

        $feed = JournalQuery::feed($lead)->get();

        $this->assertSame([Journal::DEAL_OPENED, Journal::PIN_TAKEN], $feed->pluck('event')->sort()->values()->all());
        $this->assertStringNotContainsString('Беридзе', json_encode($feed->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, JournalQuery::feed(User::factory()->owner()->create())->count());
    }

    public function test_feed_is_newest_first_and_limited(): void
    {
        $lead = User::factory()->teamLead()->create();
        foreach (range(1, 35) as $i) {
            Journal::record(Journal::PIN_TAKEN, null, ['listing_id' => $i], $lead, now()->subMinutes(100 - $i));
        }

        $feed = JournalQuery::feed($lead)->get();

        $this->assertCount(30, $feed);
        $this->assertSame(35, $feed->first()->properties['listing_id']);
    }
}
