<?php

namespace Tests\Feature\Crm;

use App\Access\OrgAccess;
use App\Crm\Deals;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Enums\Direction;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Deal;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class DealPartnerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $lead;

    private User $nino;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->lead = User::factory()->teamLead($this->company, Direction::Rent)->create();
        $this->nino = User::factory()->agent($this->lead)->create();
        $listing = Listing::fromApi(RealEstateFake::listing([
            'id' => 51,
            'deal_type' => ['id' => 2, 'slug' => 'rent', 'title' => 'rent'],
        ]), 'ru');
        $pin = app(Pins::class)->take($listing, $this->nino);
        $this->deal = app(Deals::class)->open($pin, Client::factory()->create(), $this->nino);
    }

    /** Агент другого тимлида; направление и компанию задаёт вызывающий. */
    private function otherAgent(?Company $company = null, Direction $direction = Direction::Sale): User
    {
        $lead = User::factory()->teamLead($company ?? $this->company, $direction)->create();

        return User::factory()->agent($lead)->create();
    }

    private function events(string $name): int
    {
        return Activity::query()->where('event', $name)->count();
    }

    public function test_colleague_of_same_company_can_be_partner(): void
    {
        $partner = $this->otherAgent();

        app(Deals::class)->setPartner($this->deal, $partner, $this->nino);

        $this->assertSame($partner->id, $this->deal->fresh()->partner_user_id);
        $this->assertTrue(app(Deals::class)->isPartner($this->deal->fresh(), $partner));
        $this->assertSame(1, $this->events(Journal::DEAL_PARTNER_SET));
    }

    public function test_other_company_only_with_exchange(): void
    {
        $other = Company::factory()->create();
        $partner = $this->otherAgent($other);

        try {
            app(Deals::class)->setPartner($this->deal, $partner, $this->nino);
            $this->fail('Partner from a company without exchange was accepted.');
        } catch (CrmForbidden) {
            $this->assertNull($this->deal->fresh()->partner_user_id);
        }

        CompanyExchange::create([
            'company_a_id' => min($this->company->id, $other->id),
            'company_b_id' => max($this->company->id, $other->id),
            'enabled' => true,
        ]);

        app(Deals::class)->setPartner($this->deal, $partner, $this->nino);
        $this->assertSame($partner->id, $this->deal->fresh()->partner_user_id);
    }

    public function test_blocked_or_archived_or_owner_role_rejected(): void
    {
        $candidates = [
            User::factory()->agent($this->lead)->blocked()->create(),
            User::factory()->agent($this->lead)->archived()->create(),
            User::factory()->companyOwner($this->company)->create(),
        ];

        foreach ($candidates as $candidate) {
            $this->assertFalse(OrgAccess::canPartner($this->nino, $candidate));
            try {
                app(Deals::class)->setPartner($this->deal, $candidate, $this->nino);
                $this->fail('Candidate was accepted.');
            } catch (CrmForbidden) {
                $this->assertNull($this->deal->fresh()->partner_user_id);
            }
        }
    }

    public function test_agent_himself_rejected(): void
    {
        $this->expectException(CrmForbidden::class);

        app(Deals::class)->setPartner($this->deal, $this->nino, $this->nino);
    }

    public function test_replace_and_remove_journal(): void
    {
        $first = $this->otherAgent();
        $second = $this->otherAgent();
        $deals = app(Deals::class);

        $deals->setPartner($this->deal, $first, $this->nino);
        $deals->setPartner($this->deal, $first, $this->nino); // тот же — ничего
        $this->assertSame(1, $this->events(Journal::DEAL_PARTNER_SET));

        $deals->setPartner($this->deal, $second, $this->nino);
        $set = Activity::query()->where('event', Journal::DEAL_PARTNER_SET)->latest('id')->first();
        $this->assertSame($first->id, $set->properties['previous_id']);
        $this->assertSame($second->id, $set->properties['partner_id']);

        $deals->setPartner($this->deal, null, $this->nino);
        $this->assertNull($this->deal->fresh()->partner_user_id);
        $removed = Activity::query()->where('event', Journal::DEAL_PARTNER_REMOVED)->first();
        $this->assertSame('manual', $removed->properties['reason']);
        $this->assertSame($second->id, $removed->properties['partner_id']);

        $deals->setPartner($this->deal, null, $this->nino); // уже пусто — ничего
        $this->assertSame(1, $this->events(Journal::DEAL_PARTNER_REMOVED));
    }

    public function test_partner_cannot_set_partner(): void
    {
        $partner = $this->otherAgent();
        $third = $this->otherAgent();
        app(Deals::class)->setPartner($this->deal, $partner, $this->nino);

        $this->expectException(CrmForbidden::class);

        app(Deals::class)->setPartner($this->deal->fresh(), $third, $partner);
    }
}
