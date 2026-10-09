<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_all_companies(): void
    {
        $companies = Company::factory()->count(2)->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ListCompanies::class)->assertCanSeeTableRecords($companies);
    }

    public function test_company_owner_sees_only_own_company(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        $this->actingAs(User::factory()->companyOwner($a)->create());

        Livewire::test(ListCompanies::class)
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b]);
    }

    public function test_agent_has_no_access(): void
    {
        $lead = User::factory()->teamLead()->create();

        $this->actingAs(User::factory()->agent($lead)->create())->get('/admin/companies')->assertForbidden();
    }

    public function test_owner_creates_company_and_name_is_unique(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'GS Home Vake'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('companies', ['name' => 'GS Home Vake']);

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'GS Home Vake'])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_owner_archives_and_restores_company(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ListCompanies::class)->callAction(TestAction::make('archive')->table($company));
        $this->assertTrue($company->refresh()->isArchived());

        Livewire::test(ListCompanies::class)->callAction(TestAction::make('restore')->table($company));
        $this->assertFalse($company->refresh()->isArchived());
    }

    public function test_existing_name_with_extra_spaces_is_a_form_error_not_a_crash(): void
    {
        Company::factory()->create(['name' => 'GS Home Vake']);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => ' GS  Home Vake '])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }
}
