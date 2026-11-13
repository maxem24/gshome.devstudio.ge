<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_has_no_widgets(): void
    {
        $agent = User::factory()->agent(User::factory()->teamLead()->create())->create();

        $this->actingAs($agent)->get('/admin')
            ->assertOk()
            ->assertDontSee(__('gshome.crm.feed.title'))
            ->assertDontSee('filament-info')
            ->assertDontSee('filament.widgets.filament-info')
            ->assertSee(__('gshome.quick_search.title'));
    }
}
