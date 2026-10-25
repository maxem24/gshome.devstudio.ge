<?php

namespace Tests\Feature\Filament;

use App\Crm\Journal;
use App\Filament\Pages\JournalPage;
use App\Filament\RelationManagers\JournalRelationManager;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Widgets\CrmFeed;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class JournalPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_has_no_journal_access(): void
    {
        $agent = User::factory()->agent(User::factory()->teamLead()->create())->create();

        $this->actingAs($agent)->get(JournalPage::getUrl())->assertForbidden();
    }

    public function test_lead_sees_own_people_and_filters_by_listing(): void
    {
        $lead = User::factory()->teamLead()->create();
        $mine = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $foreign = User::factory()->agent(User::factory()->teamLead()->create())->create(['name' => 'Чужая']);
        foreach ([13, 13, 14] as $id) {
            Journal::record(Journal::LISTING_VIEWED, null, ['listing_id' => $id], $mine);
        }
        Journal::record(Journal::PIN_TAKEN, null, ['listing_id' => 13], $foreign);
        $this->actingAs($lead);

        Livewire::test(JournalPage::class)
            ->assertCountTableRecords(3)
            ->filterTable('listing_id', ['listing_id' => 13])
            ->assertCountTableRecords(2)
            ->assertSee('Просмотр объекта')
            ->assertDontSee('Чужая');
    }

    public function test_feed_widget_shows_line_without_client(): void
    {
        $lead = User::factory()->teamLead(Company::factory()->create())->create();
        $agent = User::factory()->agent($lead)->create(['name' => 'Нино']);
        Journal::record(Journal::PIN_TAKEN, null, ['listing_id' => 13], $agent);
        $this->actingAs($agent);

        Livewire::test(CrmFeed::class)->assertSee('Нино закрепил(а) объект №13');
    }

    public function test_relation_manager_hidden_for_agent_and_scoped_for_lead(): void
    {
        $company = Company::factory()->create();
        $lead = User::factory()->teamLead($company)->create();
        $mine = User::factory()->agent($lead)->create();
        $foreign = User::factory()->agent(User::factory()->teamLead($company)->create())->create();
        Journal::record(Journal::ARCHIVED, $company, [], $mine);
        Journal::record(Journal::RESTORED, $company, [], $foreign);

        $this->actingAs($mine);
        $this->assertFalse(JournalRelationManager::canViewForRecord($company, EditCompany::class));

        $this->actingAs($lead);
        $this->assertTrue(JournalRelationManager::canViewForRecord($company, EditCompany::class));
        Livewire::test(JournalRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class])
            ->assertCountTableRecords(1);
    }
}
