<?php

namespace Tests\Feature\Filament;

use App\Crm\Journal;
use App\Enums\ClientHousing;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
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
