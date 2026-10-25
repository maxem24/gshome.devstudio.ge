<?php

namespace Tests\Feature\Filament;

use App\Crm\Pins;
use App\Filament\Pages\MyObjects;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class MyObjectsPageTest extends TestCase
{
    use RefreshDatabase;

    private function take(int $id, User $by): void
    {
        app(Pins::class)->take(Listing::fromApi(RealEstateFake::listing(['id' => $id, 'title' => ['ru' => "Объект $id"]]), 'ru'), $by);
    }

    public function test_my_objects_lists_own_pins_lead_sees_agents(): void
    {
        RealEstateFake::configure();
        RealEstateFake::fake(cards: [
            21 => RealEstateFake::listing(['id' => 21, 'title' => ['ru' => 'Объект 21']]),
            22 => RealEstateFake::listing(['id' => 22, 'title' => ['ru' => 'Объект 22']]),
        ]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $giorgi = User::factory()->agent($lead)->create(['name' => 'Гиорги']);
        $this->take(21, $nino);
        $this->take(22, $giorgi);

        $this->actingAs($nino);
        Livewire::test(MyObjects::class)
            ->assertSee('Объект 21')
            ->assertDontSee('Объект 22');

        $this->actingAs($lead);
        Livewire::test(MyObjects::class)
            ->assertSee('Объект 21')
            ->assertSee('Объект 22')
            ->set('agent', $giorgi->id)
            ->assertDontSee('Объект 21')
            ->assertSee('Объект 22')
            ->set('agent', null)
            ->assertSee('Объект 21');
    }

    public function test_agent_cannot_widen_view_with_agent_filter(): void
    {
        RealEstateFake::configure();
        RealEstateFake::fake(cards: [22 => RealEstateFake::listing(['id' => 22, 'title' => ['ru' => 'Объект 22']])]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create();
        $giorgi = User::factory()->agent($lead)->create();
        $this->take(22, $giorgi);

        $this->actingAs($nino);
        Livewire::test(MyObjects::class)->set('agent', $giorgi->id)->assertDontSee('Объект 22');
    }

    public function test_my_objects_survives_missing_listing(): void
    {
        RealEstateFake::configure();
        RealEstateFake::fake(cards: [21 => RealEstateFake::listing(['id' => 21, 'title' => ['ru' => 'Объект 21']])]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $this->take(21, $nino);
        $this->take(14, $nino); // карточки 14 в API нет

        $this->actingAs($nino);
        Livewire::test(MyObjects::class)
            ->assertSee('Объект 21')
            ->assertSee(__('gshome.crm.my_objects.listing_unavailable', ['id' => 14]));
    }

    public function test_my_objects_shows_error_when_api_down(): void
    {
        RealEstateFake::configure();
        RealEstateFake::fake();
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $this->take(21, $nino);
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->actingAs($nino);
        Livewire::test(MyObjects::class)
            ->assertOk()
            ->assertSee(__('gshome.crm.my_objects.listing_unavailable', ['id' => 21]));
    }
}
