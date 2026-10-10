<?php

namespace Tests\Feature\RealEstate;

use App\Enums\Direction;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use App\RealEstate\ListingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        RealEstateFake::fake();
    }

    public function test_renting_agent_gets_only_rent_even_when_asking_for_sale(): void
    {
        $lead = User::factory()->teamLead(direction: Direction::Rent)->create();

        $this->assertSame('2', ListingQuery::params($lead, [], null)['deal_type_id']);
        $this->assertSame('2', ListingQuery::params($lead, ['direction' => ['value' => 'sale']], null)['deal_type_id']);
    }

    public function test_both_directions_and_switcher(): void
    {
        $lead = User::factory()->teamLead()->create(['both_directions' => true]);

        $this->assertSame('1,2', ListingQuery::params($lead, [], null)['deal_type_id']);
        $this->assertSame('2', ListingQuery::params($lead, ['direction' => ['value' => 'rent']], null)['deal_type_id']);
    }

    public function test_filters_map_to_api_parameters(): void
    {
        $owner = User::factory()->owner()->create();

        $p = ListingQuery::params($owner, [
            'price' => ['min' => '50000', 'max' => '120000'],
            'location' => ['city_id' => '1', 'district_id' => '3'],
            'area' => ['min' => '40', 'max' => null],
            'rooms' => ['values' => ['2', '5+']],
            'property_type' => ['value' => '1'],
            'source' => ['value' => 'ss_ge'],
            'owners_only' => ['isActive' => true],
            'created' => ['from' => '2026-10-01', 'until' => '2026-10-09'],
            'sort' => ['value' => 'price_desc'],
        ], 'Ваке');

        $this->assertSame('50000', $p['price_usd_min']);
        $this->assertSame('120000', $p['price_usd_max']);
        $this->assertSame('1', $p['city_id']);
        $this->assertSame('3', $p['district_id']);
        $this->assertSame('40', $p['area_min']);
        $this->assertArrayNotHasKey('area_max', $p);
        $this->assertSame('2,5+', $p['rooms']);
        $this->assertSame('1', $p['property_type_id']);
        $this->assertSame('ss_ge', $p['source']);
        $this->assertSame('1', $p['is_owner']);
        $this->assertSame('2026-10-01', $p['created_since']);
        $this->assertStringStartsWith('2026-10-09T23:59:59', $p['created_until']);
        $this->assertSame('-price_usd', $p['sort']);
        $this->assertSame('Ваке', $p['q']);
        $this->assertSame('25', $p['per_page']);
    }

    public function test_short_search_is_ignored(): void
    {
        $this->assertArrayNotHasKey('q', ListingQuery::params(User::factory()->owner()->create(), [], 'В'));
    }

    public function test_ownership_filters_use_identifiable_phones(): void
    {
        [$a, $b, $c] = Company::factory()->count(3)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $lead = User::factory()->teamLead($a)->create();
        $pa = Phone::factory()->create(['company_id' => $a->id])->number;
        $pb = Phone::factory()->create(['company_id' => $b->id])->number;
        $pc = Phone::factory()->create(['company_id' => $c->id])->number;

        $ours = explode(',', ListingQuery::params($lead, ['ownership' => ['value' => 'ours']], null)['phones']);
        $this->assertEqualsCanonicalizing([$pa, $pb], $ours);
        $this->assertNotContains($pc, $ours);

        $others = ListingQuery::params($lead, ['ownership' => ['value' => 'others']], null);
        $this->assertEqualsCanonicalizing([$pa, $pb], explode(',', $others['exclude_phones']));
    }

    public function test_employee_filter_only_for_identifiable_people(): void
    {
        $a = Company::factory()->create();
        $lead = User::factory()->teamLead($a)->create();
        $colleague = User::factory()->agent($lead)->create();
        $phone = Phone::factory()->heldBy($colleague)->create()->number;
        $stranger = User::factory()->teamLead()->create(); // другая компания без обмена
        Phone::factory()->heldBy($stranger)->create();

        $this->assertSame($phone, ListingQuery::params($lead, ['employee' => ['value' => (string) $colleague->id]], null)['phones']);
        $this->assertNull(ListingQuery::params($lead, ['employee' => ['value' => (string) $stranger->id]], null));
    }

    public function test_ours_without_any_numbers_means_empty_result_without_request(): void
    {
        $lead = User::factory()->teamLead()->create();

        $this->assertNull(ListingQuery::params($lead, ['ownership' => ['value' => 'ours']], null));
    }

    public function test_phone_filters_ignored_when_disabled(): void
    {
        RealEstateFake::configure(phoneFilters: false);
        $lead = User::factory()->teamLead()->create();
        Phone::factory()->heldBy($lead)->create();

        $p = ListingQuery::params($lead, ['ownership' => ['value' => 'ours']], null);
        $this->assertArrayNotHasKey('phones', $p);
    }
}
