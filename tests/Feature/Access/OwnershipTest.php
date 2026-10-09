<?php

namespace Tests\Feature\Access;

use App\Access\OrgAccess;
use App\Access\Ownership;
use App\Enums\OwnershipLabel;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Каждая клетка таблицы из спецификации (раздел «Метка объявления»).
 * Компания A — своя для смотрящего, B — в паре с A с включённым обменом,
 * C — в паре с A с выключенным обменом.
 */
class OwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Company $c;

    private User $agentA;

    private User $leadA;

    private User $ownerA;

    private User $owner;

    private const PHONE_A = '+995555000101';

    private const PHONE_B = '+995555000202';

    private const PHONE_C = '+995555000303';

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Company::factory()->create(['name' => 'Компания A']);
        $this->b = Company::factory()->create(['name' => 'Компания B']);
        $this->c = Company::factory()->create(['name' => 'Компания C']);
        CompanyExchange::create(['company_a_id' => $this->a->id, 'company_b_id' => $this->b->id, 'enabled' => true]);
        CompanyExchange::create(['company_a_id' => $this->a->id, 'company_b_id' => $this->c->id, 'enabled' => false]);

        $this->owner = User::factory()->owner()->create();
        $this->ownerA = User::factory()->companyOwner($this->a)->create();
        $this->leadA = User::factory()->teamLead($this->a)->create(['name' => 'Лида A']);
        $this->agentA = User::factory()->agent($this->leadA)->create(['name' => 'Агент A']);
        $leadB = User::factory()->teamLead($this->b)->create(['name' => 'Лида B']);
        $leadC = User::factory()->teamLead($this->c)->create(['name' => 'Лида C']);

        Phone::factory()->heldBy($this->agentA)->create(['number' => self::PHONE_A]);
        Phone::factory()->heldBy($leadB)->create(['number' => self::PHONE_B]);
        Phone::factory()->heldBy($leadC)->create(['number' => self::PHONE_C]);
    }

    private function assertOwnership(Ownership $o, OwnershipLabel $label, ?string $company, ?string $name, ?string $phone): void
    {
        $this->assertSame($label, $o->label);
        $this->assertSame($company, $o->companyName);
        $this->assertSame($name, $o->contactName);
        $this->assertSame($phone, $o->contactPhone);
    }

    public function test_agent_and_team_lead_see_own_company_employee(): void
    {
        foreach ([$this->agentA, $this->leadA] as $viewer) {
            $this->assertOwnership(OrgAccess::ownership($viewer, self::PHONE_A), OwnershipLabel::OurEmployee, 'Компания A', 'Агент A', self::PHONE_A);
        }
    }

    public function test_agent_and_team_lead_see_linked_company_when_exchange_enabled(): void
    {
        foreach ([$this->agentA, $this->leadA] as $viewer) {
            $this->assertOwnership(OrgAccess::ownership($viewer, self::PHONE_B), OwnershipLabel::LinkedCompany, 'Компания B', 'Лида B', self::PHONE_B);
        }
    }

    public function test_agent_and_team_lead_see_other_company_when_exchange_disabled(): void
    {
        foreach ([$this->agentA, $this->leadA] as $viewer) {
            $this->assertOwnership(OrgAccess::ownership($viewer, self::PHONE_C), OwnershipLabel::OtherCompany, null, null, null);
        }
    }

    public function test_agent_sees_other_company_for_group_company_without_any_pair(): void
    {
        $d = Company::factory()->create();
        $leadD = User::factory()->teamLead($d)->create();
        Phone::factory()->heldBy($leadD)->create(['number' => '+995555000404']);

        $this->assertOwnership(OrgAccess::ownership($this->agentA, '+995555000404'), OwnershipLabel::OtherCompany, null, null, null);
    }

    public function test_company_owner_sees_any_group_company_regardless_of_exchange(): void
    {
        $this->assertOwnership(OrgAccess::ownership($this->ownerA, self::PHONE_A), OwnershipLabel::GroupCompany, 'Компания A', 'Агент A', self::PHONE_A);
        $this->assertOwnership(OrgAccess::ownership($this->ownerA, self::PHONE_B), OwnershipLabel::GroupCompany, 'Компания B', 'Лида B', self::PHONE_B);
        $this->assertOwnership(OrgAccess::ownership($this->ownerA, self::PHONE_C), OwnershipLabel::GroupCompany, 'Компания C', 'Лида C', self::PHONE_C);
    }

    public function test_owner_sees_every_group_company(): void
    {
        $this->assertOwnership(OrgAccess::ownership($this->owner, self::PHONE_C), OwnershipLabel::GroupCompany, 'Компания C', 'Лида C', self::PHONE_C);
    }

    public function test_unknown_number_is_other_company_for_everyone(): void
    {
        foreach ([$this->agentA, $this->leadA, $this->ownerA, $this->owner] as $viewer) {
            $this->assertOwnership(OrgAccess::ownership($viewer, '+995555999999'), OwnershipLabel::OtherCompany, null, null, null);
        }
    }

    public function test_number_in_other_writing_is_recognised(): void
    {
        $this->assertSame(OwnershipLabel::OurEmployee, OrgAccess::ownership($this->agentA, '555 00 01 01')->label);
    }

    public function test_garbage_or_missing_phone_is_other_company_without_exception(): void
    {
        $this->assertSame(OwnershipLabel::OtherCompany, OrgAccess::ownership($this->agentA, 'звоните')->label);
        $this->assertSame(OwnershipLabel::OtherCompany, OrgAccess::ownership($this->agentA, null)->label);
    }

    public function test_archived_company_is_not_ours(): void
    {
        $this->b->update(['archived_at' => now()]);

        $this->assertOwnership(OrgAccess::ownership($this->owner, self::PHONE_B), OwnershipLabel::OtherCompany, null, null, null);
        $this->assertOwnership(OrgAccess::ownership($this->agentA, self::PHONE_B), OwnershipLabel::OtherCompany, null, null, null);
    }

    public function test_number_without_holder_shows_company_and_number(): void
    {
        Phone::query()->where('number', self::PHONE_A)->update(['user_id' => null]);

        $this->assertOwnership(OrgAccess::ownership($this->leadA, self::PHONE_A), OwnershipLabel::OurEmployee, 'Компания A', null, self::PHONE_A);
    }

    public function test_blocked_holder_is_shown_as_usual(): void
    {
        User::query()->whereKey($this->agentA->id)->update(['status' => 'blocked']);

        $this->assertOwnership(OrgAccess::ownership($this->leadA, self::PHONE_A), OwnershipLabel::OurEmployee, 'Компания A', 'Агент A', self::PHONE_A);
    }

    public function test_archived_holder_name_is_hidden(): void
    {
        User::query()->whereKey($this->agentA->id)->update(['status' => 'archived']);

        $this->assertOwnership(OrgAccess::ownership($this->leadA, self::PHONE_A), OwnershipLabel::OurEmployee, 'Компания A', null, self::PHONE_A);
    }
}
