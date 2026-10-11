<?php

namespace Tests\Feature\Access;

use App\Access\OrgAccess;
use App\Enums\OwnershipLabel;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnershipManyTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_single_ownership_for_every_number_and_role(): void
    {
        [$a, $b, $c] = Company::factory()->count(3)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $leadA = User::factory()->teamLead($a)->create();
        $numbers = [];
        foreach ([$a, $b, $c] as $company) {
            $holder = User::factory()->teamLead($company)->create();
            $numbers[] = Phone::factory()->heldBy($holder)->create()->number;
        }
        $numbers[] = '+995599000000'; // не наш

        foreach ([$leadA, User::factory()->companyOwner($a)->create(), User::factory()->owner()->create()] as $viewer) {
            $many = OrgAccess::ownershipMany($viewer, $numbers);
            foreach ($numbers as $n) {
                $this->assertEquals(OrgAccess::ownership($viewer, $n), $many[$n], "{$viewer->role->value} {$n}");
            }
        }
    }

    public function test_page_of_many_numbers_costs_constant_queries(): void
    {
        $a = Company::factory()->create();
        $viewer = User::factory()->teamLead($a)->create();
        $numbers = Phone::factory()->count(20)->create(['company_id' => $a->id])->pluck('number')->all();

        DB::enableQueryLog();
        OrgAccess::ownershipMany($viewer, $numbers);
        $this->assertLessThanOrEqual(4, count(DB::getQueryLog()));
    }

    public function test_identifiable_companies_by_role(): void
    {
        [$a, $b, $c] = Company::factory()->count(3)->create()->all();
        $archived = Company::factory()->archived()->create();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $c->id, 'enabled' => false]);
        $lead = User::factory()->teamLead($a)->create();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], OrgAccess::identifiableCompanyIds($lead));
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], OrgAccess::identifiableCompanyIds(User::factory()->companyOwner($a)->create()));
        $this->assertNotContains($archived->id, OrgAccess::identifiableCompanyIds(User::factory()->owner()->create()));
    }

    public function test_masked_or_empty_numbers_are_other_company(): void
    {
        $viewer = User::factory()->owner()->create();

        $this->assertSame([], OrgAccess::ownershipMany($viewer, [null, '']));
        $this->assertSame(OwnershipLabel::OtherCompany, OrgAccess::ownership($viewer, '555402***')->label);
    }
}
