<?php

namespace Tests\Feature\Filament;

use App\Crm\Journal;
use App\Enums\ClientHousing;
use App\Enums\Direction;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ClientEditTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): User
    {
        return User::factory()->agent(User::factory()->teamLead()->create())->create();
    }

    public function test_creator_edits_name_status_and_profile(): void
    {
        $agent = $this->agent();
        $client = Client::factory()->create(['created_by' => $agent->id, 'first_name' => 'Нино', 'profile' => ['note' => 'старое']]);
        $this->actingAs($agent);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->assertFormSet(['first_name' => 'Нино', 'note' => 'старое'])
            ->assertFormFieldDoesNotExist('phone')
            ->fillForm([
                'first_name' => 'Нина',
                'housing' => ClientHousing::Renting->value,
                'rooms_min' => 2,
                'price_max' => 700,
                'note' => 'новое',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $client->refresh();
        $this->assertSame('Нина', $client->first_name);
        $this->assertSame(ClientHousing::Renting, $client->housing);
        $this->assertEquals(['rooms_min' => 2, 'price_max' => 700, 'note' => 'новое'], $client->profile);
        $this->assertSame(1, Activity::query()->where('event', Journal::CLIENT_UPDATED)->count());
    }

    public function test_stranger_without_deal_cannot_edit(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($this->agent());

        $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertForbidden();
        Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])->assertActionHidden('edit');
    }

    public function test_owner_sees_edit_action(): void
    {
        $client = Client::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])->assertActionVisible('edit');
    }

    public function test_active_client_sees_offers_without_landlord_note(): void
    {
        $holder = User::factory()->agent(User::factory()->teamLead(null, Direction::Rent)->create())->create(['name' => 'Нино']);
        ListingPin::query()->create([
            'listing_id' => 77, 'pin_key' => 'l77', 'object_id' => ListingObject::factory()->create(['subdistrict_id' => 101, 'price_usd' => 600, 'rooms' => 2, 'title' => 'Квартира в Ваке', 'snapshot_at' => now()])->id,
            'direction' => Direction::Rent, 'user_id' => $holder->id,
            'status' => ListingPin::ACTIVE, 'last_activity_at' => now(),
            'preferences' => ['note' => 'Секрет собственника'],
        ]);
        $client = Client::factory()->create(['housing' => ClientHousing::Looking, 'profile' => ['subdistrict_ids' => [101], 'price_max' => 550]]);
        $this->actingAs(User::factory()->agent(User::factory()->teamLead(null, Direction::Rent)->create())->create());

        Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])
            ->assertSee(__('gshome.crm.matching.offers_title'))
            ->assertSee('Квартира в Ваке')
            ->assertSee('$600')
            ->assertSee(__('gshome.crm.matching.in_work_other'))
            ->assertSee('цена +$50')
            ->assertDontSee('Нино')
            ->assertDontSee('Секрет собственника');
    }

    public function test_inactive_client_has_no_offers(): void
    {
        $client = Client::factory()->create(['housing' => ClientHousing::Renting]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])->assertDontSee(__('gshome.crm.matching.offers_title'));
    }

    /** Статус меняет любой, кто видит клиента (агент звонит, а клиенту уже не надо), но след остаётся в журнале. */
    public function test_any_agent_changes_status_and_it_is_journaled(): void
    {
        $client = Client::factory()->create(['housing' => ClientHousing::Looking]);
        $stranger = $this->agent();
        $this->actingAs($stranger);

        Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])
            ->callAction('housing', data: ['housing' => ClientHousing::Renting->value])
            ->assertHasNoActionErrors();

        $this->assertSame(ClientHousing::Renting, $client->fresh()?->housing);
        $entry = Activity::query()->where('event', Journal::CLIENT_UPDATED)->sole();
        $this->assertSame($stranger->id, $entry->causer_id);
    }

    public function test_archived_client_is_not_editable(): void
    {
        $client = Client::factory()->create();
        $client->delete();
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);

        $this->assertFalse($owner->can('update', $client));
        $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertNotFound();
    }

    public function test_deal_partner_can_edit(): void
    {
        $partner = $this->agent();
        $client = Client::factory()->create();
        $pin = ListingPin::query()->create([
            'listing_id' => 88, 'pin_key' => 'l88', 'object_id' => ListingObject::factory()->create()->id, 'direction' => Direction::Rent, 'user_id' => $this->agent()->id,
            'status' => ListingPin::ACTIVE, 'last_activity_at' => now(),
        ]);
        DB::table('deals')->insert([
            'listing_pin_id' => $pin->id, 'client_id' => $client->id, 'user_id' => $pin->user_id, 'partner_user_id' => $partner->id,
            'stage' => 'call', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue($partner->can('update', $client));
    }

    public function test_status_labels_say_active_or_not(): void
    {
        $this->assertSame('Активен — ищет квартиру', ClientHousing::Looking->getLabel());
        $this->assertSame('Неактивен — уже не нужно', ClientHousing::Renting->getLabel());
    }

    public function test_clients_without_status_become_active(): void
    {
        $client = Client::factory()->create();
        DB::table('clients')->where('id', $client->id)->update(['housing' => null]);

        (require database_path('migrations/2026_11_18_133000_clients_active_by_default.php'))->up();

        $this->assertSame(ClientHousing::Looking, $client->fresh()?->housing);
        $this->assertSame(ClientHousing::Looking, Client::factory()->create()->fresh()?->housing);
    }
}
