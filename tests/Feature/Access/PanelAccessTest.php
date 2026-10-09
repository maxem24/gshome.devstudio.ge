<?php

namespace Tests\Feature\Access;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_users_of_every_role_enter(): void
    {
        $lead = User::factory()->teamLead()->create();
        foreach ([User::factory()->owner()->create(), User::factory()->companyOwner()->create(), $lead, User::factory()->agent($lead)->create()] as $user) {
            $this->actingAs($user)->get('/admin')->assertOk();
        }
    }

    public function test_archived_user_is_refused(): void
    {
        $this->actingAs(User::factory()->teamLead()->archived()->create())->get('/admin')->assertForbidden();
    }

    public function test_blocked_user_is_refused(): void
    {
        $this->actingAs(User::factory()->teamLead()->blocked()->create())->get('/admin')->assertForbidden();
    }

    public function test_employee_of_archived_company_is_refused(): void
    {
        $lead = User::factory()->teamLead(Company::factory()->archived()->create())->create();

        $this->actingAs($lead)->get('/admin')->assertForbidden();
    }
}
