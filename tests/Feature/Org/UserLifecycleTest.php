<?php

namespace Tests\Feature\Org;

use App\Enums\Direction;
use App\Enums\UserStatus;
use App\Models\Phone;
use App\Models\User;
use App\Org\UserLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

class UserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ── Блокировка: временно, всё остаётся на месте ─────────────────────────

    public function test_blocked_user_keeps_numbers_agents_and_place(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        $phone = Phone::factory()->heldBy($lead)->create();

        UserLifecycle::block($lead);

        $this->assertSame(UserStatus::Blocked, $lead->refresh()->status);
        $this->assertSame($lead->id, $phone->refresh()->user_id);
        $this->assertSame($lead->id, $agent->refresh()->team_lead_id);
    }

    public function test_blocked_user_is_cut_off_on_the_next_request(): void
    {
        $lead = User::factory()->teamLead()->create();
        $this->actingAs($lead)->get('/admin')->assertOk();

        UserLifecycle::block($lead);

        $this->get('/admin')->assertForbidden();
    }

    public function test_unblock_restores_access(): void
    {
        $lead = User::factory()->teamLead()->blocked()->create();

        UserLifecycle::unblock($lead);

        $this->assertSame(UserStatus::Active, $lead->refresh()->status);
        $this->actingAs($lead)->get('/admin')->assertOk();
    }

    public function test_owner_cannot_be_blocked_or_archived(): void
    {
        $owner = User::factory()->owner()->create();

        $this->expectException(InvalidArgumentException::class);
        UserLifecycle::block($owner);
    }

    public function test_only_active_user_can_be_blocked(): void
    {
        $lead = User::factory()->teamLead()->archived()->create();

        $this->expectException(InvalidArgumentException::class);
        UserLifecycle::block($lead);
    }

    // ── Архив: навсегда, с передачей номеров и сотрудников ──────────────────

    public function test_archiving_agent_passes_numbers_to_colleague(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        $colleague = User::factory()->agent($lead)->create();
        $phone = Phone::factory()->heldBy($agent)->create();

        UserLifecycle::archive($agent, $colleague->id, null);

        $this->assertSame(UserStatus::Archived, $agent->refresh()->status);
        $this->assertSame($colleague->id, $phone->refresh()->user_id);
    }

    public function test_archiving_without_new_holder_leaves_numbers_with_company(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        $phone = Phone::factory()->heldBy($agent)->create();

        UserLifecycle::archive($agent, null, null);

        $this->assertNull($phone->refresh()->user_id);
        $this->assertSame($agent->company_id, $phone->company_id);
    }

    public function test_blocked_user_can_be_archived(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->blocked()->create();

        UserLifecycle::archive($agent, null, null);

        $this->assertSame(UserStatus::Archived, $agent->refresh()->status);
    }

    public function test_team_lead_with_working_agents_needs_a_new_team_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        User::factory()->agent($lead)->blocked()->create();

        $this->expectException(InvalidArgumentException::class);
        UserLifecycle::archive($lead, null, null);
    }

    public function test_archiving_team_lead_moves_working_agents_to_new_team_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        $active = User::factory()->agent($lead)->create();
        $blocked = User::factory()->agent($lead)->blocked()->create();
        $newLead = User::factory()->teamLead($lead->company)->create();

        UserLifecycle::archive($lead, null, $newLead->id);

        $this->assertSame($newLead->id, $active->refresh()->team_lead_id);
        $this->assertSame($newLead->id, $blocked->refresh()->team_lead_id);
        $this->assertSame(UserStatus::Archived, $lead->refresh()->status);
    }

    public function test_team_lead_whose_agents_are_all_archived_can_be_archived_without_new_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        UserLifecycle::archive($agent, null, null);

        UserLifecycle::archive($lead->refresh(), null, null);

        $this->assertSame(UserStatus::Archived, $lead->refresh()->status);
        $this->assertSame($lead->id, $agent->refresh()->team_lead_id);
    }

    public function test_new_team_lead_must_be_from_same_company_and_team(): void
    {
        $lead = User::factory()->teamLead()->create();
        User::factory()->agent($lead)->create();
        $rentLead = User::factory()->teamLead($lead->company, Direction::Rent)->create();

        $this->expectException(InvalidArgumentException::class);
        UserLifecycle::archive($lead, null, $rentLead->id);
    }

    public function test_options_offer_only_active_people_and_exclude_the_person(): void
    {
        $lead = User::factory()->teamLead()->create();
        $other = User::factory()->teamLead($lead->company)->create();
        $blocked = User::factory()->teamLead($lead->company)->blocked()->create();

        $this->assertArrayNotHasKey($lead->id, UserLifecycle::teamLeadOptions($lead));
        $this->assertArrayHasKey($other->id, UserLifecycle::teamLeadOptions($lead));
        $this->assertArrayNotHasKey($blocked->id, UserLifecycle::teamLeadOptions($lead));
        $this->assertArrayNotHasKey($lead->id, UserLifecycle::phoneHolderOptions($lead));
        $this->assertArrayNotHasKey($blocked->id, UserLifecycle::phoneHolderOptions($lead));
    }

    // ── Возврат из архива ───────────────────────────────────────────────────

    public function test_restore_returns_access(): void
    {
        $owner = User::factory()->companyOwner()->archived()->create();

        UserLifecycle::restore($owner, null);

        $this->assertSame(UserStatus::Active, $owner->refresh()->status);
    }

    public function test_restoring_agent_whose_team_lead_is_archived_needs_a_new_team_lead(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        UserLifecycle::archive($agent, null, null);
        UserLifecycle::archive($lead->refresh(), null, null);
        $newLead = User::factory()->teamLead($lead->company)->create();

        try {
            UserLifecycle::restore($agent->refresh(), null);
            $this->fail('Сотрудник вернулся к архивному тимлиду');
        } catch (InvalidArgumentException) {
            $this->assertSame(UserStatus::Archived, $agent->refresh()->status);
        }

        UserLifecycle::restore($agent, $newLead->id);

        $this->assertSame(UserStatus::Active, $agent->refresh()->status);
        $this->assertSame($newLead->id, $agent->team_lead_id);
    }

    // ── Пароль ──────────────────────────────────────────────────────────────

    public function test_change_password_sets_new_one_and_rotates_remember_token(): void
    {
        $user = User::factory()->companyOwner()->create(['remember_token' => 'old-token']);

        UserLifecycle::changePassword($user, 'new-secret-123');

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-123', $user->password));
        $this->assertNotSame('old-token', $user->remember_token);
    }

    public function test_change_password_ends_open_sessions(): void
    {
        $user = User::factory()->companyOwner()->create();
        $this->actingAs($user)
            ->withSession(['password_hash_web' => $user->getAuthPassword()])
            ->get('/admin')
            ->assertOk();

        UserLifecycle::changePassword($user, 'new-secret-123');

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_short_password_is_rejected(): void
    {
        $user = User::factory()->companyOwner()->create();

        $this->expectException(InvalidArgumentException::class);
        UserLifecycle::changePassword($user, 'short');
    }
}
