<?php

namespace Tests\Feature\Org;

use App\Enums\Direction;
use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class UserPlacementTest extends TestCase
{
    use RefreshDatabase;

    /** Вставка мимо модели: проверяем CHECK-ограничения базы, а не хуки. */
    private function insertUser(array $attributes): void
    {
        DB::table('users')->insert(array_merge([
            'name' => 'X',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'x',
            'role' => 'agent',
            'company_id' => null,
            'direction' => null,
            'team_lead_id' => null,
            'both_directions' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    public function test_database_rejects_owner_with_company(): void
    {
        $company = Company::factory()->create();
        $this->expectException(QueryException::class);
        $this->insertUser(['role' => 'owner', 'company_id' => $company->id]);
    }

    public function test_database_rejects_team_lead_without_direction(): void
    {
        $company = Company::factory()->create();
        $this->expectException(QueryException::class);
        $this->insertUser(['role' => 'team_lead', 'company_id' => $company->id]);
    }

    public function test_database_rejects_agent_without_team_lead(): void
    {
        $company = Company::factory()->create();
        $this->expectException(QueryException::class);
        $this->insertUser(['role' => 'agent', 'company_id' => $company->id, 'direction' => 'sale']);
    }

    public function test_database_rejects_team_lead_id_on_non_agent(): void
    {
        $lead = User::factory()->teamLead()->create();
        $this->expectException(QueryException::class);
        $this->insertUser([
            'role' => 'company_owner',
            'company_id' => $lead->company_id,
            'team_lead_id' => $lead->id,
        ]);
    }

    public function test_role_change_clears_fields_the_new_role_does_not_have(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create(['both_directions' => true]);

        $agent->update(['role' => Role::CompanyOwner]);

        $agent->refresh();
        $this->assertSame(Role::CompanyOwner, $agent->role);
        $this->assertNull($agent->direction);
        $this->assertNull($agent->team_lead_id);
        $this->assertFalse($agent->both_directions);
        $this->assertSame($lead->company_id, $agent->company_id);
    }

    public function test_owner_loses_company(): void
    {
        $user = User::factory()->companyOwner()->create();

        $user->update(['role' => Role::Owner]);

        $this->assertNull($user->refresh()->company_id);
    }

    public function test_agent_team_lead_must_be_from_same_company(): void
    {
        $lead = User::factory()->teamLead()->create();
        $other = Company::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        User::factory()->create([
            'role' => Role::Agent,
            'company_id' => $other->id,
            'direction' => Direction::Sale,
            'team_lead_id' => $lead->id,
        ]);
    }

    public function test_agent_team_lead_must_be_from_same_direction(): void
    {
        $lead = User::factory()->teamLead(direction: Direction::Rent)->create();

        $this->expectException(InvalidArgumentException::class);
        User::factory()->create([
            'role' => Role::Agent,
            'company_id' => $lead->company_id,
            'direction' => Direction::Sale,
            'team_lead_id' => $lead->id,
        ]);
    }

    public function test_agent_team_lead_must_be_a_team_lead(): void
    {
        $owner = User::factory()->companyOwner()->create();

        $this->expectException(InvalidArgumentException::class);
        User::factory()->create([
            'role' => Role::Agent,
            'company_id' => $owner->company_id,
            'direction' => Direction::Sale,
            'team_lead_id' => $owner->id,
        ]);
    }

    public function test_email_and_name_are_normalized(): void
    {
        $user = User::factory()->create(['email' => '  Max+CL@AbsoluteWeb.com ', 'name' => "  Max \t  CL "]);

        $this->assertSame('max+cl@absoluteweb.com', $user->refresh()->email);
        $this->assertSame('Max CL', $user->name);
    }

    public function test_company_name_is_squished(): void
    {
        $company = Company::factory()->create(['name' => '  GS   Home  ']);

        $this->assertSame('GS Home', $company->refresh()->name);
    }

    public function test_team_lead_with_agents_cannot_change_company_direction_or_role(): void
    {
        $lead = User::factory()->teamLead()->create();
        User::factory()->agent($lead)->create();

        foreach ([['company_id' => Company::factory()->create()->id], ['direction' => Direction::Rent], ['role' => Role::CompanyOwner]] as $change) {
            try {
                $lead->refresh()->update($change);
                $this->fail('Изменение '.json_encode(array_keys($change)).' прошло при живых сотрудниках');
            } catch (InvalidArgumentException) {
                $this->assertSame(Role::TeamLead, $lead->refresh()->role);
            }
        }
    }

    public function test_team_lead_without_agents_can_be_moved(): void
    {
        $lead = User::factory()->teamLead()->create();
        $other = Company::factory()->create();

        $lead->update(['company_id' => $other->id]);

        $this->assertSame($other->id, $lead->refresh()->company_id);
    }
}
