<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CompanyExchanges\Pages\ManageCompanyExchanges;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyExchangeResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_pair(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ManageCompanyExchanges::class)
            ->callAction('create', data: ['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true])
            ->assertHasNoFormErrors();

        $this->assertTrue(CompanyExchange::enabledBetween($a->id, $b->id));
    }

    public function test_reversed_existing_pair_is_a_form_error(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => false]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ManageCompanyExchanges::class)
            ->callAction('create', data: ['company_a_id' => $b->id, 'company_b_id' => $a->id, 'enabled' => true])
            ->assertHasFormErrors(['company_b_id']);
    }

    public function test_same_company_twice_is_a_form_error(): void
    {
        $a = Company::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ManageCompanyExchanges::class)
            ->callAction('create', data: ['company_a_id' => $a->id, 'company_b_id' => $a->id, 'enabled' => true])
            ->assertHasFormErrors(['company_b_id']);
    }

    public function test_owner_toggles_pair(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        $pair = CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ManageCompanyExchanges::class)
            ->callAction(TestAction::make('toggle')->table($pair));

        $this->assertFalse($pair->refresh()->enabled);
    }

    public function test_company_owner_has_no_access(): void
    {
        $this->actingAs(User::factory()->companyOwner()->create())
            ->get('/admin/company-exchanges')
            ->assertForbidden();
    }
}
