<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Filament\Pages\Listings;
use App\Livewire\FindListing;
use App\Models\Company;
use App\Models\ListingSearch;
use App\Models\User;
use App\Search\SiteSearches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class FindListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        Queue::fake();
    }

    private function seller(): User
    {
        $lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();

        return User::factory()->agent($lead)->create();
    }

    public function test_button_is_in_the_header_for_every_role(): void
    {
        RealEstateFake::fake([RealEstateFake::page([])]);
        $lead = User::factory()->teamLead()->create();

        foreach ([User::factory()->owner()->create(), User::factory()->companyOwner()->create(), $lead, User::factory()->agent($lead)->create()] as $user) {
            $this->actingAs($user)->get('/admin/listings')->assertOk()->assertSee(__('gshome.search.find'));
        }
    }

    public function test_show_in_base_redirects_with_listings_filters(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        $data = [
            'deal' => 'rent', 'city_id' => 1, 'district_id' => [1], 'property_type_id' => 1,
            'price_usd_min' => 300, 'price_usd_max' => 900, 'area_min' => 40, 'area_max' => 80, 'rooms' => [2, 5],
        ];
        $url = Listings::getUrl(['filters' => FindListing::baseFilters($data)]);

        Livewire::test(FindListing::class)->callAction('find', $data, ['base' => true])->assertRedirect($url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('rent', $q['filters']['direction']['value']);

        // Тот самый адрес открывает экран объектов, и фильтры доходят до API.
        $this->get($url)->assertOk();
        $api = Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/listings?'))->last()[0]->url();
        parse_str((string) parse_url($api, PHP_URL_QUERY), $params);
        $this->assertSame('2', $params['deal_type_id']);
        $this->assertSame('300', $params['price_usd_min']);
        $this->assertSame('900', $params['price_usd_max']);
        $this->assertSame('40', $params['area_min']);
        $this->assertSame('80', $params['area_max']);
        $this->assertSame('1', $params['city_id']);
        $this->assertSame('1', $params['district_id']);
        $this->assertSame('1', $params['property_type_id']);
        $this->assertSame('2,5+', $params['rooms']);
    }

    public function test_search_starts_and_shows_notice(): void
    {
        RealEstateFake::fake(searchPosts: [Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $user = $this->seller();
        $this->actingAs($user);

        Livewire::test(FindListing::class)
            ->callAction('find', ['city_id' => 1, 'property_type_id' => 1])
            ->assertNotified(__('gshome.search.started'));

        $this->assertSame(1, ListingSearch::query()->where('user_id', $user->id)->count());
    }

    public function test_filters_required_keeps_modal_open_with_message(): void
    {
        RealEstateFake::fake(searchPosts: [Http::response(['error' => ['code' => 'filters_required', 'message' => 'x']], 422)]);
        $this->actingAs($this->seller());

        Livewire::test(FindListing::class)
            ->callAction('find', [])
            ->assertNotified(__('gshome.search.filters_required'))
            ->assertActionMounted('find');

        $this->assertStringContainsString('Сузьте поиск', __('gshome.search.filters_required'));
        $this->assertSame(0, ListingSearch::query()->count());
    }

    public function test_rejected_place_shows_the_server_text_as_is(): void
    {
        $text = 'Микрорайон «Самгори» (Исани-Самгори) не входит в выбранные районы: район и микрорайон должны совпадать. Добавьте его район или уберите микрорайон.';
        RealEstateFake::fake(searchPosts: [Http::response(['error' => ['code' => 'validation_failed', 'message' => '…', 'fields' => ['subdistrict_id' => [$text]]]], 422)]);
        $this->actingAs($this->seller());

        Livewire::test(FindListing::class)
            ->callAction('find', ['city_id' => 1])
            ->assertNotified($text)
            ->assertActionMounted('find');
    }

    /** Тбилиси (1): Ваке (10) с Ваке-центром (101), Исани-Самгори (20) с Самгори (201) и Варкетили (202). */
    private function fakePlaces(array $searchPosts = []): void
    {
        $row = fn (int $id, string $ru): array => ['id' => $id, 'slug' => (string) $id, 'title_ka' => $ru, 'title_en' => $ru, 'title_ru' => $ru];
        // Раньше общего фейка: первый ответивший колбэк Http::fake выигрывает, null пропускает дальше.
        Http::fake(function (Request $request) use ($row): mixed {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return match (true) {
                $path === '/api/v1/dictionaries/districts' => Http::response(['data' => [$row(10, 'Ваке'), $row(20, 'Исани-Самгори')]]),
                $path === '/api/v1/dictionaries/subdistricts' && ($q['district_id'] ?? null) === '10' => Http::response(['data' => [$row(101, 'Ваке-центр')]]),
                $path === '/api/v1/dictionaries/subdistricts' && ($q['district_id'] ?? null) === '20' => Http::response(['data' => [$row(201, 'Самгори'), $row(202, 'Варкетили')]]),
                default => null,
            };
        });
        RealEstateFake::fake(searchPosts: $searchPosts);
    }

    public function test_whole_district_beside_subdistricts_of_another_is_refused_before_the_api(): void
    {
        $this->fakePlaces([Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $this->actingAs($this->seller());

        Livewire::test(FindListing::class)
            ->callAction('find', ['city_id' => 1, 'district_id' => [10, 20], 'subdistrict_id' => [201]])
            ->assertHasActionErrors(['district_id'])
            ->assertActionMounted('find');

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/search-jobs'));
        $this->assertSame(0, ListingSearch::query()->count());
    }

    public function test_subdistricts_with_their_district_or_whole_districts_alone_are_fine(): void
    {
        $this->fakePlaces();

        $this->assertSame([], FindListing::wholeDistrictsBesideSubdistricts([1], [10, 20], [101, 201]));
        $this->assertSame([], FindListing::wholeDistrictsBesideSubdistricts([1], [20], [201]));
        $this->assertSame([], FindListing::wholeDistrictsBesideSubdistricts([1], [10, 20], []));
    }

    public function test_listed_districts_without_chosen_subdistricts_are_named(): void
    {
        $this->fakePlaces();

        $this->assertSame(['Ваке'], FindListing::wholeDistrictsBesideSubdistricts([1], [10, 20], [201, 202]));
    }

    public function test_district_with_its_subdistricts_reaches_the_api(): void
    {
        $this->fakePlaces([Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $this->actingAs($this->seller());

        Livewire::test(FindListing::class)
            ->callAction('find', ['city_id' => 1, 'district_id' => [20], 'subdistrict_id' => [201, 202]])
            ->assertHasNoErrors()
            ->assertNotified(__('gshome.search.started'));

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/search-jobs')
            && $r['district_id'] === [20] && $r['subdistrict_id'] === [201, 202]);
    }

    public function test_dropping_a_district_keeps_the_other_districts_subdistricts(): void
    {
        $this->fakePlaces();
        $this->actingAs($this->seller());

        Livewire::test(FindListing::class)
            ->mountAction('find')
            ->fillForm(['city_id' => 1, 'district_id' => [10, 20]])
            ->fillForm(['subdistrict_id' => [101, 201]])
            ->fillForm(['district_id' => [20]])
            ->assertSchemaStateSet(['subdistrict_id' => [201]]);
    }

    public function test_repeat_start_says_already_running_then_offers_fresh_result(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $user = $this->seller();
        $this->actingAs($user);

        Livewire::test(FindListing::class)->callAction('find', ['city_id' => 1])->assertNotified(__('gshome.search.started'));
        Livewire::test(FindListing::class)->callAction('find', ['city_id' => 1])->assertNotified(__('gshome.search.started_running'));

        app(SiteSearches::class)->close(ListingSearch::query()->firstOrFail(), 'done');
        Livewire::test(FindListing::class)->callAction('find', ['city_id' => 1])->assertNotified(__('gshome.search.reused'));
        $this->assertSame(1, ListingSearch::query()->count());
    }

    public function test_rate_limit_shows_quota_message(): void
    {
        RealEstateFake::fake(searchPosts: [Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '60'])]);
        $this->actingAs($this->seller());

        Livewire::test(FindListing::class)
            ->callAction('find', ['city_id' => 1])
            ->assertNotified(__('gshome.search.rate_limited'))
            ->assertActionMounted('find');
    }

    public function test_deal_select_is_always_there_and_defaults_to_the_team_direction(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());
        Livewire::test(FindListing::class)->mountAction('find')
            ->assertSchemaComponentVisible('deal', 'mountedActionSchema0')
            ->assertSchemaStateSet(['deal' => 'sale'], 'mountedActionSchema0');

        $lead = User::factory()->teamLead(Company::factory()->create(), Direction::Rent)->create();
        $this->actingAs($lead);
        Livewire::test(FindListing::class)->mountAction('find')->assertSchemaStateSet(['deal' => 'rent'], 'mountedActionSchema0');

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(FindListing::class)->mountAction('find')
            ->assertSchemaComponentVisible('deal', 'mountedActionSchema0')
            ->assertSchemaStateSet(['deal' => null], 'mountedActionSchema0');
    }

    public function test_other_direction_toggle_only_for_one_direction_users(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());
        Livewire::test(FindListing::class)->mountAction('find')->assertSchemaComponentVisible('other_direction', 'mountedActionSchema0');

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(FindListing::class)->mountAction('find')->assertSchemaComponentHidden('other_direction', 'mountedActionSchema0');
    }

    public function test_toggle_switches_the_deal_field_to_the_other_direction_and_back(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        Livewire::test(FindListing::class)->mountAction('find')
            ->fillForm(['other_direction' => true])
            ->assertSchemaStateSet(['deal' => 'rent'], 'mountedActionSchema0')
            ->fillForm(['other_direction' => false])
            ->assertSchemaStateSet(['deal' => 'sale'], 'mountedActionSchema0');
    }

    public function test_sale_agent_searches_rent_through_the_hidden_option(): void
    {
        RealEstateFake::fake(searchPosts: [Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $this->actingAs($this->seller());

        Livewire::test(FindListing::class)
            ->callAction('find', ['city_id' => 1, 'property_type_id' => 1, 'deal' => 'sale', 'other_direction' => true, 'price_usd_max' => 500])
            ->assertNotified(__('gshome.search.started'));

        $search = ListingSearch::query()->firstOrFail();
        $this->assertSame('rent', $search->filters['deal']);
        $this->assertArrayNotHasKey('other_direction', $search->filters);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'search-jobs') && ($r->data()['deal_type_id'] ?? null) === [2]);
    }

    public function test_show_in_base_is_refused_for_the_other_direction(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        Livewire::test(FindListing::class)
            ->callAction('find', ['city_id' => 1, 'other_direction' => true], ['base' => true])
            ->assertNotified(__('gshome.search.base_own_only'))
            ->assertNoRedirect();
    }

    public function test_low_sale_price_warns_but_rent_does_not_and_submit_(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());
        Livewire::test(FindListing::class)->mountAction('find')
            ->assertSchemaComponentHidden('low_price_warning', 'mountedActionSchema0')
            ->fillForm(['price_usd_max' => 500])
            ->assertSchemaComponentVisible('low_price_warning', 'mountedActionSchema0')
            ->fillForm(['other_direction' => true])
            ->assertSchemaComponentHidden('low_price_warning', 'mountedActionSchema0')
            ->fillForm(['other_direction' => false, 'price_usd_max' => 90000])
            ->assertSchemaComponentHidden('low_price_warning', 'mountedActionSchema0');

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(FindListing::class)->mountAction('find')
            ->fillForm(['deal' => 'rent', 'price_usd_max' => 500])
            ->assertSchemaComponentHidden('low_price_warning', 'mountedActionSchema0')
            ->fillForm(['deal' => 'sale'])
            ->assertSchemaComponentVisible('low_price_warning', 'mountedActionSchema0');
    }

    public function test_low_sale_price_does_not_block_submit(): void
    {
        RealEstateFake::fake(searchPosts: [Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $this->actingAs($this->seller());
        Livewire::test(FindListing::class)
            ->callAction('find', ['city_id' => 1, 'property_type_id' => 1, 'price_usd_max' => 500])
            ->assertNotified(__('gshome.search.started'));
        $this->assertSame(1, ListingSearch::query()->count());
    }
}
