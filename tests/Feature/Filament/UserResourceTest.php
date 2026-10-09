<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->owner()->create();
    }

    public function test_owner_creates_agent_under_team_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        $this->actingAs($this->owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Нино',
                'email' => 'nino@example.ge',
                'password' => 'secret-pass',
                'role' => Role::Agent->value,
                'company_id' => $lead->company_id,
                'direction' => Direction::Sale->value,
                'team_lead_id' => $lead->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $agent = User::query()->where('email', 'nino@example.ge')->firstOrFail();
        $this->assertSame($lead->id, $agent->team_lead_id);
        $this->assertSame(Role::Agent, $agent->role);
    }

    public function test_team_lead_from_other_direction_is_not_an_allowed_option(): void
    {
        $rentLead = User::factory()->teamLead(direction: Direction::Rent)->create();
        $this->actingAs($this->owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Нино',
                'email' => 'nino@example.ge',
                'password' => 'secret-pass',
                'role' => Role::Agent->value,
                'company_id' => $rentLead->company_id,
                'direction' => Direction::Sale->value,
                'team_lead_id' => $rentLead->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['team_lead_id']);
    }

    public function test_existing_email_in_other_case_is_a_form_error_not_a_crash(): void
    {
        User::factory()->companyOwner()->create(['email' => 'giorgi@example.ge']);
        $company = Company::factory()->create();
        $this->actingAs($this->owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Гиорги',
                'email' => 'Giorgi@Example.GE',
                'password' => 'secret-pass',
                'role' => Role::CompanyOwner->value,
                'company_id' => $company->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_changing_role_on_edit_clears_team_fields(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create(['both_directions' => true]);
        $this->actingAs($this->owner);

        Livewire::test(EditUser::class, ['record' => $agent->getRouteKey()])
            ->fillForm(['role' => Role::CompanyOwner->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $agent->refresh();
        $this->assertSame(Role::CompanyOwner, $agent->role);
        $this->assertNull($agent->direction);
        $this->assertNull($agent->team_lead_id);
        $this->assertFalse($agent->both_directions);
    }

    public function test_password_is_kept_when_left_empty_on_edit(): void
    {
        $user = User::factory()->companyOwner()->create();
        $hash = $user->password;
        $this->actingAs($this->owner);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($hash, $user->refresh()->password);
    }

    public function test_company_owner_lists_only_own_company_people(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        $ownerA = User::factory()->companyOwner($a)->create();
        $leadA = User::factory()->teamLead($a)->create();
        $leadB = User::factory()->teamLead($b)->create();
        $this->actingAs($ownerA);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$ownerA, $leadA])
            ->assertCanNotSeeTableRecords([$leadB, $this->owner]);
    }

    public function test_company_owner_cannot_open_edit(): void
    {
        $company = Company::factory()->create();
        $ownerA = User::factory()->companyOwner($company)->create();
        $lead = User::factory()->teamLead($company)->create();

        $this->actingAs($ownerA)->get("/admin/users/{$lead->id}/edit")->assertForbidden();
        $this->actingAs($ownerA)->get("/admin/users/{$lead->id}")->assertOk();
    }
}
