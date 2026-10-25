<?php

namespace Tests\Feature\Filament;

use App\Crm\DealStages;
use App\Filament\Pages\DealStagesSettings;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DealStagesSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_a_renamed_stage(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(DealStagesSettings::class)
            ->assertSchemaStateSet(fn (array $state): bool => $state['stages'] !== [])
            ->fillForm(function (array $state): array {
                $items = $state['stages'];
                $first = array_key_first($items);
                $items[$first]['labels']['ru'] = 'Первый звонок';

                return ['stages' => $items];
            })
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Первый звонок', DealStages::find('call')->label('ru'));
        $this->assertCount(6, DealStages::all());
    }

    public function test_page_only_for_owner(): void
    {
        $company = Company::factory()->create();
        $lead = User::factory()->teamLead($company)->create();
        $users = [
            User::factory()->companyOwner($company)->create(),
            $lead,
            User::factory()->agent($lead)->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user);
            $this->assertFalse(DealStagesSettings::canAccess());
            Livewire::test(DealStagesSettings::class)->assertForbidden();
        }
    }
}
