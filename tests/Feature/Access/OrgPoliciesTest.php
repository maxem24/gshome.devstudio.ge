<?php

namespace Tests\Feature\Access;

use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgPoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_manages_everything_but_never_deletes_companies_or_users(): void
    {
        $owner = User::factory()->owner()->create();
        $company = Company::factory()->create();
        $lead = User::factory()->teamLead($company)->create();
        $phone = Phone::factory()->heldBy($lead)->create();
        $pair = CompanyExchange::create(['company_a_id' => $company->id, 'company_b_id' => Company::factory()->create()->id, 'enabled' => true]);

        foreach ([[Company::class, $company], [User::class, $lead], [Phone::class, $phone], [CompanyExchange::class, $pair]] as [$class, $record]) {
            $this->assertTrue($owner->can('viewAny', $class));
            $this->assertTrue($owner->can('view', $record));
            $this->assertTrue($owner->can('create', $class));
            $this->assertTrue($owner->can('update', $record));
        }

        $this->assertFalse($owner->can('delete', $company));
        $this->assertFalse($owner->can('delete', $lead));
        $this->assertTrue($owner->can('delete', $pair));
    }

    public function test_company_owner_reads_only_own_company(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $ownerA = User::factory()->companyOwner($a)->create();
        $leadA = User::factory()->teamLead($a)->create();
        $leadB = User::factory()->teamLead($b)->create();
        $phoneA = Phone::factory()->heldBy($leadA)->create();
        $phoneB = Phone::factory()->heldBy($leadB)->create();

        $this->assertTrue($ownerA->can('viewAny', Company::class));
        $this->assertTrue($ownerA->can('view', $a));
        $this->assertFalse($ownerA->can('view', $b));
        $this->assertTrue($ownerA->can('view', $leadA));
        $this->assertFalse($ownerA->can('view', $leadB));
        $this->assertTrue($ownerA->can('view', $phoneA));
        $this->assertFalse($ownerA->can('view', $phoneB));

        $this->assertFalse($ownerA->can('create', User::class));
        $this->assertFalse($ownerA->can('update', $a));
        $this->assertFalse($ownerA->can('update', $leadA));
        $this->assertFalse($ownerA->can('viewAny', CompanyExchange::class));
    }

    public function test_team_lead_and_agent_see_no_org_screens(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();

        foreach ([$lead, $agent] as $user) {
            foreach ([Company::class, User::class, Phone::class, CompanyExchange::class] as $class) {
                $this->assertFalse($user->can('viewAny', $class), $class);
            }
        }
    }
}
