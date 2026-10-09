<?php

namespace Tests\Feature\Filament;

use App\Enums\PhoneKind;
use App\Filament\Resources\Phones\Pages\CreatePhone;
use App\Filament\Resources\Phones\Pages\ListPhones;
use App\Models\Company;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PhoneResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_adds_number_in_national_format(): void
    {
        $lead = User::factory()->teamLead()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreatePhone::class)
            ->fillForm([
                'number' => '555 12 34 56',
                'kind' => PhoneKind::Personal->value,
                'company_id' => $lead->company_id,
                'user_id' => $lead->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('phones', ['number' => '+995555123456', 'user_id' => $lead->id]);
    }

    public function test_existing_number_in_other_writing_is_a_form_error_not_a_crash(): void
    {
        $company = Company::factory()->create();
        Phone::factory()->create(['number' => '+995555123456', 'company_id' => $company->id]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreatePhone::class)
            ->fillForm([
                'number' => '0555 12 34 56',
                'kind' => PhoneKind::Office->value,
                'company_id' => $company->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['number']);
    }

    public function test_foreign_number_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreatePhone::class)
            ->fillForm([
                'number' => '+7 916 123 45 67',
                'kind' => PhoneKind::Office->value,
                'company_id' => $company->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['number']);
    }

    public function test_holder_from_other_company_is_not_an_allowed_option(): void
    {
        $company = Company::factory()->create();
        $foreignLead = User::factory()->teamLead()->create();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(CreatePhone::class)
            ->fillForm([
                'number' => '555 12 34 56',
                'kind' => PhoneKind::Personal->value,
                'company_id' => $company->id,
                'user_id' => $foreignLead->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['user_id']);
    }

    public function test_company_owner_sees_only_own_company_numbers(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        $phoneA = Phone::factory()->create(['company_id' => $a->id]);
        $phoneB = Phone::factory()->create(['company_id' => $b->id]);
        $this->actingAs(User::factory()->companyOwner($a)->create());

        Livewire::test(ListPhones::class)
            ->assertCanSeeTableRecords([$phoneA])
            ->assertCanNotSeeTableRecords([$phoneB]);
    }
}
