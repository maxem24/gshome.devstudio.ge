<?php

namespace Tests\Feature\RealEstate;

use App\Enums\Direction;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use App\RealEstate\Exceptions\TooManyPhones;
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
        $this->assertSame('2026-10-09', $p['created_until']);
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

    public function test_garbage_dates_are_ignored(): void
    {
        $p = ListingQuery::params(User::factory()->owner()->create(), [
            'created' => ['from' => '2026-13-45', 'until' => 'garbage'],
        ], null);

        $this->assertArrayNotHasKey('created_since', $p);
        $this->assertArrayNotHasKey('created_until', $p);
    }

    public function test_more_than_500_numbers_is_refused_for_ours(): void
    {
        $company = Company::factory()->create();
        $lead = User::factory()->teamLead($company)->create();
        Phone::factory()->count(ListingQuery::MAX_PHONES + 1)->create(['company_id' => $company->id]);

        $this->expectException(TooManyPhones::class);
        ListingQuery::params($lead, ['ownership' => ['value' => 'ours']], null);
    }

    public function test_more_than_500_numbers_is_refused_for_others(): void
    {
        $company = Company::factory()->create();
        $lead = User::factory()->teamLead($company)->create();
        Phone::factory()->count(ListingQuery::MAX_PHONES + 1)->create(['company_id' => $company->id]);

        $this->expectException(TooManyPhones::class);
        ListingQuery::params($lead, ['ownership' => ['value' => 'others']], null);
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

    public function test_garbage_filter_values_are_ignored_without_errors(): void
    {
        $owner = User::factory()->owner()->create();

        $p = ListingQuery::params($owner, [
            'rooms' => ['values' => 'x'],
            'price' => 'x',
            'area' => ['min' => ['1'], 'max' => ['2']],
            'location' => ['city_id' => ['1'], 'district_id' => ['2']],
            'direction' => ['value' => ['sale']],
            'created' => ['from' => ['x'], 'until' => 5],
            'sort' => ['value' => ['price_desc']],
            'source' => ['value' => ['ss_ge']],
            'property_type' => 'x',
            'employee' => ['value' => ['1']],
            'ownership' => ['value' => ['ours']],
        ], null);

        $this->assertIsArray($p);
        foreach (['rooms', 'price_usd_min', 'area_min', 'area_max', 'city_id', 'district_id', 'created_since', 'created_until', 'sort', 'source', 'property_type_id'] as $key) {
            $this->assertArrayNotHasKey($key, $p, $key);
        }
    }

    public function test_garbage_scalar_values_are_not_sent_to_the_api(): void
    {
        $owner = User::factory()->owner()->create();

        $p = ListingQuery::params($owner, [
            'location' => ['city_id' => 'abc', 'district_id' => '-3'],
            'price' => ['min' => '-1e9', 'max' => 'NaN'],
            'area' => ['min' => '-5', 'max' => 'x'],
            'source' => ['value' => 'evil'],
            'property_type' => ['value' => '1.5'],
            'employee' => ['value' => 'abc'],
        ], null);

        $this->assertIsArray($p);
        foreach (['city_id', 'district_id', 'price_usd_min', 'price_usd_max', 'area_min', 'area_max', 'source', 'property_type_id', 'phones'] as $key) {
            $this->assertArrayNotHasKey($key, $p, $key);
        }

        $ok = ListingQuery::params($owner, [
            'location' => ['city_id' => '1', 'district_id' => '2'], 'price' => ['min' => '100.5', 'max' => '0'],
            'source' => ['value' => 'ss_ge'], 'property_type' => ['value' => '3'],
        ], null);
        $this->assertSame(['1', '2', '100.5', '0', 'ss_ge', '3'], [$ok['city_id'], $ok['district_id'], $ok['price_usd_min'], $ok['price_usd_max'], $ok['source'], $ok['property_type_id']]);
    }

    public function test_non_numeric_employee_is_no_filter_and_no_database_error(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (['abc', '1.5', '99999999999999999999', '-1', ''] as $value) {
            $p = ListingQuery::params($owner, ['employee' => ['value' => $value]], null);
            $this->assertIsArray($p, $value);
            $this->assertArrayNotHasKey('phones', $p, $value);
        }
    }

    public function test_reversed_date_range_is_swapped_so_the_api_does_not_answer_422(): void
    {
        $owner = User::factory()->owner()->create();

        $p = ListingQuery::params($owner, ['created' => ['from' => '2026-10-09', 'until' => '2026-10-01']], null);
        $this->assertSame(['2026-10-01', '2026-10-09'], [$p['created_since'], $p['created_until']]);

        $p = ListingQuery::params($owner, ['created' => ['from' => '2026-10-05', 'until' => '2026-10-05']], null);
        $this->assertSame(['2026-10-05', '2026-10-05'], [$p['created_since'], $p['created_until']]);
    }

    public function test_search_is_cut_to_the_api_limit(): void
    {
        $owner = User::factory()->owner()->create();

        $p = ListingQuery::params($owner, [], str_repeat('ж', 150));
        $this->assertSame(100, mb_strlen($p['q']));

        $this->assertSame(str_repeat('a', 100), ListingQuery::params($owner, [], str_repeat('a', 100))['q']);
    }

    public function test_ids_with_leading_zeros_or_zero_are_no_filter(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (['007', '0', '00'] as $value) {
            $p = ListingQuery::params($owner, [
                'location' => ['city_id' => $value, 'district_id' => $value],
                'property_type' => ['value' => $value],
                'employee' => ['value' => $value],
            ], null);
            $this->assertIsArray($p, $value);
            foreach (['city_id', 'district_id', 'property_type_id', 'phones'] as $key) {
                $this->assertArrayNotHasKey($key, $p, "{$key}={$value}");
            }
        }
    }

    public function test_others_excludes_only_phones_of_visible_companies(): void
    {
        $a = Company::factory()->create();
        $lead = User::factory()->teamLead($a)->create();
        $mine = Phone::factory()->heldBy($lead)->create()->number;
        $hidden = User::factory()->teamLead()->create(); // другая компания без обмена
        $hiddenPhone = Phone::factory()->heldBy($hidden)->create()->number;

        $p = ListingQuery::params($lead, ['ownership' => ['value' => 'others']], null);

        $this->assertSame($mine, $p['exclude_phones']);
        $this->assertStringNotContainsString($hiddenPhone, $p['exclude_phones']);
    }

    public function test_rooms_only_from_the_known_list(): void
    {
        $owner = User::factory()->owner()->create();

        $p = ListingQuery::params($owner, ['rooms' => ['values' => ['2', 'abc', '99', ['3'], '5+']]], null);

        $this->assertSame('2,5+', $p['rooms']);
        $this->assertArrayNotHasKey('rooms', ListingQuery::params($owner, ['rooms' => ['values' => ['abc']]], null));
    }
}
