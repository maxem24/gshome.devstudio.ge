<?php

namespace Tests\Feature\Crm;

use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_with_toggle_sees_trashed_others_do_not(): void
    {
        Client::factory()->create();
        Client::factory()->create()->delete();

        $owner = User::factory()->owner()->create(['show_archived' => true]);
        $this->assertCount(2, Client::query()->visibleTo($owner)->get());

        $owner->show_archived = false;
        $this->assertCount(1, Client::query()->visibleTo($owner)->get());

        $agent = User::factory()->agent(User::factory()->teamLead()->create())->create(['show_archived' => true]);
        $this->assertCount(1, Client::query()->visibleTo($agent)->get());
    }

    public function test_owner_toggle_shows_archived_client_and_restore_works(): void
    {
        $archived = Client::factory()->create();
        $archived->delete();
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);

        Livewire::test(ListClients::class)->assertCanNotSeeTableRecords([$archived]);

        $this->post(route('archive.toggle'))->assertRedirect();
        $this->assertTrue($owner->refresh()->show_archived);
        Livewire::test(ListClients::class)->assertCanSeeTableRecords([$archived]);
        Livewire::test(ViewClient::class, ['record' => $archived->id])->callAction('restore');
        $this->assertFalse($archived->refresh()->trashed());

        $this->post(route('archive.toggle'))->assertRedirect();
        $this->assertFalse($owner->refresh()->show_archived);
    }

    public function test_menu_item_only_for_owner_and_agent_cannot_toggle(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get('/admin')->assertOk()->assertSee(__('gshome.crm.archive_toggle.show'));

        $agent = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $this->actingAs($agent)->get('/admin')->assertOk()->assertDontSee(__('gshome.crm.archive_toggle.show'));
        $this->post(route('archive.toggle'))->assertForbidden();
        $this->assertFalse($agent->refresh()->show_archived);
    }
}
