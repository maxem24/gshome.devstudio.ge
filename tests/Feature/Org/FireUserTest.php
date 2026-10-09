<?php

namespace Tests\Feature\Org;

use App\Enums\Direction;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Phone;
use App\Models\User;
use App\Org\FireUser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class FireUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_firing_agent_passes_numbers_to_colleague(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        $colleague = User::factory()->agent($lead)->create();
        $phone = Phone::factory()->heldBy($agent)->create();

        FireUser::handle($agent, $colleague->id, null);

        $this->assertFalse($agent->refresh()->is_active);
        $this->assertSame($colleague->id, $phone->refresh()->user_id);
    }

    public function test_firing_without_new_holder_leaves_numbers_with_company(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        $phone = Phone::factory()->heldBy($agent)->create();

        FireUser::handle($agent, null, null);

        $this->assertNull($phone->refresh()->user_id);
        $this->assertSame($agent->company_id, $phone->company_id);
    }

    public function test_team_lead_with_agents_needs_a_new_team_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        User::factory()->agent($lead)->create();

        $this->expectException(InvalidArgumentException::class);
        FireUser::handle($lead, null, null);
    }

    public function test_firing_team_lead_moves_agents_to_new_team_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agents = User::factory()->count(2)->agent($lead)->create();
        $newLead = User::factory()->teamLead($lead->company)->create();

        FireUser::handle($lead, null, $newLead->id);

        foreach ($agents as $agent) {
            $this->assertSame($newLead->id, $agent->refresh()->team_lead_id);
        }
        $this->assertFalse($lead->refresh()->is_active);
    }

    public function test_new_team_lead_must_be_from_same_company_and_team(): void
    {
        $lead = User::factory()->teamLead()->create();
        User::factory()->agent($lead)->create();
        $rentLead = User::factory()->teamLead($lead->company, Direction::Rent)->create();

        $this->expectException(InvalidArgumentException::class);
        FireUser::handle($lead, null, $rentLead->id);
    }

    public function test_options_exclude_the_fired_person(): void
    {
        $lead = User::factory()->teamLead()->create();
        $other = User::factory()->teamLead($lead->company)->create();

        $this->assertArrayNotHasKey($lead->id, FireUser::teamLeadOptions($lead));
        $this->assertArrayHasKey($other->id, FireUser::teamLeadOptions($lead));
        $this->assertArrayNotHasKey($lead->id, FireUser::phoneHolderOptions($lead));
    }

    public function test_owner_fires_and_rehires_from_the_table(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ListUsers::class)->callAction(TestAction::make('fire')->table($agent));
        $this->assertFalse($agent->refresh()->is_active);

        Livewire::test(ListUsers::class)->callAction(TestAction::make('rehire')->table($agent));
        $this->assertTrue($agent->refresh()->is_active);
    }

    public function test_team_lead_whose_agents_are_all_fired_can_be_fired_without_new_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        FireUser::handle($agent, null, null);

        FireUser::handle($lead->refresh(), null, null);

        $this->assertFalse($lead->refresh()->is_active);
        $this->assertSame($lead->id, $agent->refresh()->team_lead_id);
    }
}
