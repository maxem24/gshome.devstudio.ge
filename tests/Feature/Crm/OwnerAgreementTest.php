<?php

namespace Tests\Feature\Crm;

use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Journal;
use App\Crm\OwnerAgreements;
use App\Crm\Pins;
use App\Enums\CommissionKind;
use App\Enums\Direction;
use App\Models\Company;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class OwnerAgreementTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $nino;

    private User $giorgi;

    private ListingObject $object;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::factory()->create();
        $this->lead = User::factory()->teamLead($company, Direction::Rent)->create(['name' => 'Тимлид']);
        $this->nino = User::factory()->agent($this->lead)->create(['name' => 'Нино']);
        $this->giorgi = User::factory()->agent($this->lead)->create(['name' => 'Гиорги']);
        $this->object = ListingObject::factory()->create([
            'object_key' => 'l:41', 'listing_id' => 41, 'direction' => Direction::Rent,
        ]);
    }

    public function test_first_save_stamps_the_date_and_a_later_save_keeps_it(): void
    {
        $pin = $this->pin($this->nino);
        $pins = app(Pins::class);

        $pins->saveAgreement($pin, $this->percent(90, '1000'), $this->nino);
        $at = $pin->fresh()->agreed_at;
        $this->assertNotNull($at);
        $this->assertSame($this->nino->id, $pin->agreed_by);
        $this->assertSame(1, Activity::query()->where('event', Journal::PIN_AGREEMENT_SAVED)->count());

        $this->travel(2)->days();
        $pins->saveAgreement($pin->fresh(), $this->percent(80, '1100'), $this->lead);

        $pin->refresh();
        $this->assertTrue($at->equalTo($pin->agreed_at));
        $this->assertSame($this->nino->id, $pin->agreed_by);
        $this->assertSame(80, $pin->commission_percent);
        $this->assertSame('1100.00', $pin->agreed_price);
    }

    public function test_incomplete_agreement_is_rejected_and_preferences_only_clear_it(): void
    {
        $pin = $this->pin($this->nino);
        $pins = app(Pins::class);
        $pins->saveAgreement($pin, $this->percent(90, '1000'), $this->nino);

        try {
            $pins->saveAgreement($pin->fresh(), ['agreed_price' => '500', 'agreed_currency' => 'GEL'], $this->nino);
            $this->fail('incomplete');
        } catch (CrmForbidden) {
            $this->assertSame('1000.00', $pin->fresh()->agreed_price);
        }

        $pins->saveAgreement($pin->fresh(), ['agreement_note' => 'перезвонит'], $this->nino);
        $pin->refresh();
        $this->assertNull($pin->agreed_at);
        $this->assertSame('перезвонит', $pin->agreement_note);
    }

    public function test_agent_does_not_see_the_other_percent_and_the_lead_sees_both(): void
    {
        $nino = $this->pin($this->nino);
        $giorgi = $this->pin($this->giorgi);
        $pins = app(Pins::class);
        $pins->saveAgreement($nino, $this->percent(90, '1000'), $this->nino);
        $pins->saveAgreement($giorgi, $this->percent(30, '1000'), $this->giorgi);

        $rows = app(OwnerAgreements::class);
        $this->assertSame([], $rows->forObject($this->object, $this->nino));

        $seen = $rows->forObject($this->object, $this->lead);
        $this->assertSame(['Нино', 'Гиорги'], array_column($seen, 'name'));
        $this->assertTrue($seen[0]['best']);
        $this->assertFalse($seen[1]['best']);
        $this->assertStringContainsString('90%', $seen[0]['summary']);
        $this->assertStringContainsString('30%', $seen[1]['summary']);
        $this->assertSame('900.00', $seen[0]['expected']);
        $this->assertSame('300.00', $seen[1]['expected']);
    }

    public function test_usd_without_a_rate_is_shown_but_not_marked_best(): void
    {
        $nino = $this->pin($this->nino);
        $giorgi = $this->pin($this->giorgi);
        $pins = app(Pins::class);
        $pins->saveAgreement($nino, $this->percent(90, '1000'), $this->nino);
        $pins->saveAgreement($giorgi, $this->percent(50, '1000', 'USD'), $this->giorgi);

        $seen = app(OwnerAgreements::class)->forObject($this->object, $this->lead);

        $this->assertSame('Нино', $seen[0]['name']);
        $this->assertTrue($seen[0]['best']);
        $this->assertNull($seen[1]['expected']);
        $this->assertFalse($seen[1]['best']);
        $this->assertStringContainsString('50%', $seen[1]['summary']);
    }

    public function test_other_agent_cannot_save(): void
    {
        $this->expectException(CrmForbidden::class);
        app(Pins::class)->saveAgreement($this->pin($this->nino), $this->percent(90, '1000'), $this->giorgi);
    }

    private function pin(User $user): ListingPin
    {
        return ListingPin::query()->create([
            'listing_id' => 41, 'pin_key' => 'l:41', 'direction' => Direction::Rent,
            'user_id' => $user->id, 'status' => ListingPin::ACTIVE, 'object_id' => $this->object->id,
            'last_activity_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function percent(int $percent, string $price, string $currency = 'GEL'): array
    {
        return [
            'agreed_price' => $price,
            'agreed_currency' => $currency,
            'commission_kind' => CommissionKind::Percent->value,
            'commission_percent' => $percent,
        ];
    }
}
