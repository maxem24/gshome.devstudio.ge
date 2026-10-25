<?php

namespace Tests\Feature\Filament;

use App\Crm\Deals;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Crm\Viewings;
use App\Enums\Direction;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\DealsRelationManager;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\RealEstate\Listing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ClientResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
    }

    /** @return array<string, mixed> */
    private function form(string $phone = '555 12 34 56', string $first = 'Нино', ?string $last = 'Беридзе'): array
    {
        return ['phone' => $phone, 'first_name' => $first, 'last_name' => $last, 'tags' => ['buyer'], 'reliability' => 'unverified'];
    }

    public function test_create_new_client(): void
    {
        $agent = User::factory()->agent($this->lead)->create();
        $this->actingAs($agent);

        Livewire::test(CreateClient::class)
            ->fillForm($this->form())
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::query()->where('phone_e164', '+995555123456')->firstOrFail();
        $this->assertSame(['buyer'], $client->tags);
        $this->assertSame($agent->id, $client->created_by);
        $this->assertSame(1, Activity::query()->where('event', Journal::CLIENT_CREATED)->count());
    }

    public function test_bad_phone_and_bad_tag_rejected(): void
    {
        $this->actingAs(User::factory()->agent($this->lead)->create());

        Livewire::test(CreateClient::class)
            ->fillForm(['phone' => '123'] + $this->form())
            ->call('create')
            ->assertHasFormErrors(['phone']);
        Livewire::test(CreateClient::class)
            ->fillForm(['tags' => ['hacker']] + $this->form())
            ->call('create')
            ->assertHasFormErrors(['tags.0']);
        $this->assertSame(0, Client::query()->count());
    }

    public function test_same_phone_same_name_redirects_to_existing(): void
    {
        $existing = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Нино', 'last_name' => 'Беридзе']);
        $this->actingAs(User::factory()->agent($this->lead)->create());

        Livewire::test(CreateClient::class)
            ->fillForm($this->form('0555 12 34 56'))
            ->call('create')
            ->assertRedirect(ClientResource::getUrl('view', ['record' => $existing]))
            ->assertNotified(__('gshome.crm.clients.already_exists'));
        $this->assertSame(1, Client::query()->count());
    }

    public function test_conflict_offers_choice_and_reassign_creates_new(): void
    {
        $old = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Гиви', 'last_name' => 'Лордкипанидзе']);
        $this->actingAs(User::factory()->agent($this->lead)->create());

        Livewire::test(CreateClient::class)
            ->fillForm($this->form())
            ->call('create')
            ->assertActionMounted('resolveConflict')
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $new = Client::query()->where('phone_e164', '+995555123456')->firstOrFail();
        $this->assertSame('Нино', $new->first_name);
        $this->assertNull($old->refresh()->phone_e164);
        $this->assertSame(2, Client::query()->count());
    }

    public function test_conflict_same_person_button_opens_existing(): void
    {
        $old = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Гиви', 'last_name' => 'Лордкипанидзе']);
        $this->actingAs(User::factory()->agent($this->lead)->create());

        Livewire::test(CreateClient::class)
            ->fillForm($this->form())
            ->call('create')
            ->assertActionMounted('resolveConflict')
            ->callAction(TestAction::make('samePerson')->arguments([]), [])
            ->assertRedirect(ClientResource::getUrl('view', ['record' => $old]));
        $this->assertSame(1, Client::query()->count());
        $this->assertSame('+995555123456', $old->refresh()->phone_e164);
    }

    public function test_agent_on_archived_number_gets_no_redirect_to_hidden_card(): void
    {
        $old = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Нино', 'last_name' => 'Беридзе']);
        $old->delete();
        $this->actingAs(User::factory()->agent($this->lead)->create());

        Livewire::test(CreateClient::class)
            ->fillForm($this->form())
            ->call('create')
            ->assertNoRedirect()
            ->assertNotified(__('gshome.crm.clients.archived_exists'));
        $this->assertSame(1, Client::withTrashed()->count());
    }

    public function test_race_on_unique_phone_falls_back_to_same_flow(): void
    {
        $agent = User::factory()->agent($this->lead)->create();
        $this->actingAs($agent);
        $rival = null;
        // Другой агент успевает занять номер между match и вставкой (отдельным соединением к той же базе не сымитировать — вставляем в момент creating).
        Client::creating(function (Client $client) use (&$rival): void {
            if ($rival === null) {
                $rival = true;
                DB::table('clients')->insert([
                    'phone_e164' => $client->phone_e164, 'first_name' => 'Нино', 'last_name' => 'Беридзе',
                    'tags' => '[]', 'reliability' => 'unverified', 'created_by' => $this->lead->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        Livewire::test(CreateClient::class)
            ->fillForm($this->form())
            ->call('create')
            ->assertHasNoFormErrors();

        // Вставка соперника живёт в той же транзакции и откатывается вместе с savepoint: клиент создан со второй попытки.
        $this->assertSame(1, Client::query()->where('phone_e164', '+995555123456')->count());
    }

    public function test_no_edit_page(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        $this->assertArrayNotHasKey('edit', ClientResource::getPages());
        $this->get('/admin/clients/'.$client->id.'/edit')->assertNotFound();
        $this->assertFalse(ClientResource::canEdit($client));
    }

    public function test_agent_sees_full_history_of_client_including_other_agents_deals(): void
    {
        RealEstateFake::configure();
        $nino = User::factory()->agent($this->lead)->create();
        $giorgi = User::factory()->agent($this->lead)->create(['name' => 'Гиорги']);
        $client = Client::factory()->create();
        $listing = Listing::fromApi(RealEstateFake::listing([
            'id' => 41,
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');
        $pin = app(Pins::class)->take($listing, $giorgi);
        $deal = app(Deals::class)->open($pin, $client, $giorgi);
        app(Viewings::class)->schedule($deal, now()->addDay(), null, $giorgi);

        $this->actingAs($nino);

        Livewire::test(DealsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
            ->assertCanSeeTableRecords([$deal])
            ->assertSee('Гиорги')
            ->assertSee('41');
        $this->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk();
    }

    public function test_archived_hidden_for_agent_visible_for_owner_with_toggle(): void
    {
        $live = Client::factory()->create();
        $archived = Client::factory()->create();
        $archived->delete();

        $this->actingAs(User::factory()->agent($this->lead)->create());
        Livewire::test(ListClients::class)
            ->assertCanSeeTableRecords([$live])
            ->assertCanNotSeeTableRecords([$archived]);
        $this->get(ClientResource::getUrl('view', ['record' => $archived]))->assertNotFound();

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        Livewire::test(ListClients::class)->assertCanNotSeeTableRecords([$archived]);

        $owner->update(['show_archived' => true]);
        Livewire::test(ListClients::class)->assertCanSeeTableRecords([$live, $archived]);
    }

    public function test_search_by_any_phone_form_and_name(): void
    {
        $nino = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Нино', 'last_name' => 'Беридзе']);
        $other = Client::factory()->create(['phone_e164' => '+995599000111', 'first_name' => 'Гиви', 'last_name' => 'Кварацхелия']);
        $this->actingAs(User::factory()->agent($this->lead)->create());

        foreach (['0555 12 34 56', '+995 555 123 456', '555123', 'берид', 'Нино Бер'] as $term) {
            Livewire::test(ListClients::class)
                ->searchTable($term)
                ->assertCanSeeTableRecords([$nino])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_archive_by_creator_and_owner_restore_only_owner(): void
    {
        $agent = User::factory()->agent($this->lead)->create();
        $stranger = User::factory()->agent($this->lead)->create();
        $client = Client::factory()->create(['created_by' => $agent->id]);

        $this->actingAs($stranger);
        Livewire::test(ViewClient::class, ['record' => $client->id])
            ->assertActionHidden('archive');

        $this->actingAs($agent);
        Livewire::test(ViewClient::class, ['record' => $client->id])
            ->assertActionVisible('archive')
            ->callAction('archive');
        $this->assertTrue($client->refresh()->trashed());
        $this->assertSame(1, Activity::query()->where('event', Journal::ARCHIVED)->count());

        $owner = User::factory()->owner()->create(['show_archived' => true]);
        $this->actingAs($owner);
        Livewire::test(ViewClient::class, ['record' => $client->id])
            ->assertActionVisible('restore')
            ->callAction('restore');
        $this->assertFalse($client->refresh()->trashed());
        $this->assertSame(1, Activity::query()->where('event', Journal::RESTORED)->count());
    }
}
