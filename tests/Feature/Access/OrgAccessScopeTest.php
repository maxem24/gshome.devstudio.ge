<?php

namespace Tests\Feature\Access;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_directions_by_role(): void
    {
        $company = Company::factory()->create();
        $rentLead = User::factory()->teamLead($company, Direction::Rent)->create();

        $this->assertSame([Direction::Sale, Direction::Rent], OrgAccess::directions(User::factory()->owner()->create()));
        $this->assertSame([Direction::Sale, Direction::Rent], OrgAccess::directions(User::factory()->companyOwner($company)->create()));
        $this->assertSame([Direction::Rent], OrgAccess::directions($rentLead));
        $this->assertSame([Direction::Rent], OrgAccess::directions(User::factory()->agent($rentLead)->create()));
    }

    public function test_both_directions_flag_opens_second_direction(): void
    {
        $lead = User::factory()->teamLead()->create(['both_directions' => true]);
        $agent = User::factory()->agent($lead)->create(['both_directions' => true]);

        $this->assertSame([Direction::Sale, Direction::Rent], OrgAccess::directions($lead));
        $this->assertSame([Direction::Sale, Direction::Rent], OrgAccess::directions($agent));
    }

    public function test_visible_users_by_role(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $owner = User::factory()->owner()->create();
        $ownerA = User::factory()->companyOwner($a)->create();
        $leadA1 = User::factory()->teamLead($a)->create();
        $leadA2 = User::factory()->teamLead($a)->create();
        $agentA1 = User::factory()->agent($leadA1)->create();
        $agentA2 = User::factory()->agent($leadA2)->create();
        $leadB = User::factory()->teamLead($b)->create();

        $ids = fn (User $viewer): array => OrgAccess::visibleUsers($viewer)->orderBy('id')->pluck('id')->all();

        $this->assertSame(User::query()->orderBy('id')->pluck('id')->all(), $ids($owner));
        $this->assertSame([$ownerA->id, $leadA1->id, $leadA2->id, $agentA1->id, $agentA2->id], $ids($ownerA));
        $this->assertSame([$leadA1->id, $agentA1->id], $ids($leadA1));
        $this->assertSame([$agentA1->id], $ids($agentA1));
        $this->assertNotContains($leadB->id, $ids($ownerA));
    }

    public function test_both_directions_does_not_widen_people(): void
    {
        $lead = User::factory()->teamLead()->create(['both_directions' => true]);
        $agent = User::factory()->agent($lead)->create();
        $otherLead = User::factory()->teamLead($lead->company, Direction::Rent)->create();

        $this->assertSame([$lead->id, $agent->id], OrgAccess::visibleUsers($lead)->orderBy('id')->pluck('id')->all());
        $this->assertNotContains($otherLead->id, OrgAccess::visibleUsers($lead)->pluck('id')->all());
    }
}
