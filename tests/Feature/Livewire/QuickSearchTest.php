<?php

namespace Tests\Feature\Livewire;

use App\Enums\Direction;
use App\Filament\Pages\Listings;
use App\Livewire\FindListing;
use App\Livewire\QuickSearch;
use App\Models\Company;
use App\Models\ListingSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class QuickSearchTest extends TestCase
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

    public function test_fresh_starts_site_search_with_body(): void
    {
        RealEstateFake::fake(searchPosts: [Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $user = $this->seller();
        $this->actingAs($user);

        Livewire::test(QuickSearch::class)
            ->set('property_type_id', 1)->set('city_id', 1)->set('district_id', [1])
            ->set('bedrooms', ['2', '5'])->set('area_min', 40)->set('price_usd_max', 90000)
            ->call('submit')
            ->assertNotified(__('gshome.search.started'));

        $this->assertSame(1, ListingSearch::query()->where('user_id', $user->id)->count());
        Http::assertSent(function (Request $r): bool {
            if (! str_contains($r->url(), '/search-jobs') || $r->method() !== 'POST') {
                return false;
            }
            $b = $r->data();

            return ($b['bedrooms'] ?? null) === [2, 5, 6, 7, 8, 9, 10]
                && ($b['area_min'] ?? null) === 40 && ($b['price_usd_max'] ?? null) === 90000
                && ($b['district_id'] ?? null) === [1];
        });
    }

    public function test_base_redirects_to_listings_without_post(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        $component = Livewire::test(QuickSearch::class)
            ->set('mode', 'base')->set('property_type_id', 1)->set('city_id', 1)->set('bedrooms', ['2'])
            ->call('submit');
        $component->assertRedirect(Listings::getUrl(['filters' => FindListing::baseFilters([
            'deal' => 'sale', 'property_type_id' => 1, 'city_id' => 1, 'bedrooms' => ['2'],
        ])]));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
    }

    public function test_base_mode_other_direction_blocked(): void
    {
        RealEstateFake::fake();
        $user = $this->seller();
        $this->actingAs($user);

        // Форма открыта по своей сделке, а направление человека сменили до нажатия: проверка — на сервере, при submit.
        $component = Livewire::test(QuickSearch::class)
            ->set('mode', 'base')->set('deal', 'sale')->set('city_id', 1);
        $user->direction = Direction::Rent;

        $component->call('submit')
            ->assertNotified(__('gshome.search.base_own_only'))
            ->assertNoRedirect();
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
    }

    public function test_single_direction_hides_deal_switch(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());
        Livewire::test(QuickSearch::class)->assertDontSeeHtml('name="deal"');

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(QuickSearch::class)->assertSeeHtml('name="deal"');
    }

    public function test_rate_limited_keeps_form(): void
    {
        RealEstateFake::fake(searchPosts: [Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '17'])]);
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->set('property_type_id', 1)->set('city_id', 1)->set('bedrooms', ['3'])
            ->call('submit')
            ->assertNotified(__('gshome.search.rate_limited'))
            ->assertSet('bedrooms', ['3'])->assertSet('city_id', 1);

        $this->assertSame(0, ListingSearch::query()->count());
    }

    public function test_empty_form_cannot_submit(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        $component = Livewire::test(QuickSearch::class)
            ->set('property_type_id', null)->set('city_id', null);

        $this->assertMatchesRegularExpression('/<button\s+type="submit"\s+disabled/', $component->html());
        $component->call('submit');

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/search-jobs'));
    }

    public function test_last_values_restored_from_session(): void
    {
        RealEstateFake::fake(searchPosts: [Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->set('property_type_id', 1)->set('city_id', 1)->set('price_usd_max', 120000)->set('bedrooms', ['2'])
            ->call('submit');

        Livewire::test(QuickSearch::class)
            ->assertSet('price_usd_max', 120000)->assertSet('bedrooms', ['2'])->assertSet('mode', 'fresh');
    }

    public function test_collectors_offline_shows_warning(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)->assertSee(__('gshome.quick_search.collectors_offline'))
            ->call('useBase')->assertSet('mode', 'base')->assertDontSee(__('gshome.quick_search.collectors_offline'));
    }

    private function fakeDictionariesAndMe(?array $me): void
    {
        Http::fake(function (Request $r) use ($me): mixed {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/api/v1/dictionaries/property-types' => Http::response(['data' => [
                    ['id' => 7, 'slug' => 'apartment', 'title_ka' => 'ბინა', 'title_en' => 'Apartment', 'title_ru' => 'Квартира'],
                ]]),
                $me !== null && $path === '/api/v1/me' => Http::response(['data' => $me]),
                default => null,
            };
        });
        RealEstateFake::fake();
    }

    public function test_defaults_on_fresh_mount(): void
    {
        $this->fakeDictionariesAndMe(null);
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->assertSet('property_type_id', 7)->assertSet('city_id', 1)->assertSet('deal', 'sale')->assertSet('mode', 'fresh');
    }

    public function test_session_values_win_over_defaults(): void
    {
        $this->fakeDictionariesAndMe(null);
        $this->actingAs($this->seller());
        session(['quick_search.last' => ['mode' => 'base', 'deal' => 'sale', 'property_type_id' => 3, 'city_id' => 2, 'bedrooms' => ['4']]]);

        Livewire::test(QuickSearch::class)
            ->assertSet('property_type_id', 3)->assertSet('city_id', 2)->assertSet('mode', 'base')->assertSet('bedrooms', ['4']);
    }

    public function test_base_radio_disabled_with_hint_for_foreign_deal(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        $component = Livewire::test(QuickSearch::class)
            ->assertDontSee(__('gshome.search.base_own_only'))
            ->set('deal', 'rent')
            ->assertSee(__('gshome.search.base_own_only'));

        $this->assertMatchesRegularExpression('/value="base"\s+disabled/', $component->html());
    }

    public function test_city_change_resets_districts(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->set('district_id', [1])->set('subdistrict_id', [5])
            ->set('city_id', 2)
            ->assertSet('district_id', [])->assertSet('subdistrict_id', []);
    }

    public function test_unknown_collector_state_shows_no_warning(): void
    {
        $this->fakeDictionariesAndMe(['client' => 'GS Home', 'imports' => []]);
        $this->actingAs($this->seller());

        $component = Livewire::test(QuickSearch::class)
            ->assertDontSee(__('gshome.quick_search.collectors_offline'));
        $this->assertDoesNotMatchRegularExpression('/<button\s+type="submit"\s+disabled/', $component->html());
    }

    public function test_base_mode_falls_back_to_fresh_when_deal_leaves_own_directions(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->set('mode', 'base')->assertSet('mode', 'base')
            ->set('deal', 'rent')
            ->assertSet('mode', 'fresh');
    }

    public function test_base_mode_from_session_falls_back_to_fresh_for_foreign_deal(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());
        session(['quick_search.last' => ['mode' => 'base', 'deal' => 'rent', 'city_id' => 1]]);

        Livewire::test(QuickSearch::class)->assertSet('deal', 'rent')->assertSet('mode', 'fresh');
    }

    public function test_place_options_search_each_level(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        $component = Livewire::test(QuickSearch::class)->instance();
        $this->assertInstanceOf(QuickSearch::class, $component);
        $this->assertSame([[1, 'Тбилиси']], $component->placeOptions('cities', 'tbil'));
        $this->assertSame([], $component->placeOptions('cities', 'zzz'));
        $this->assertSame([[1, 'Тбилиси']], $component->placeOptions('districts', 'თბ', [1]));
        $this->assertSame([], $component->placeOptions('districts', '', []));
        $this->assertSame([[1, 'Тбилиси', 1]], $component->placeOptions('subdistricts', '', [1, 2]));
        $this->assertSame([], $component->placeOptions('regions', ''));
    }

    public function test_apply_place_sets_city_and_keeps_new_districts(): void
    {
        $this->fakePlaces();
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->set('city_id', 2)->set('district_id', [9])
            ->call('applyPlace', 1, ['10', 20], [201])
            ->assertSet('city_id', 1)->assertSet('district_id', [10, 20])->assertSet('subdistrict_id', [201])
            ->call('applyPlace', null, [10], [101])
            ->assertSet('city_id', null)->assertSet('district_id', [])->assertSet('subdistrict_id', []);
    }

    public function test_filled_chips_show_values(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->set('price_usd_max', 90000)->set('area_min', 50)->set('area_max', 90)->set('bedrooms', ['2', '3'])
            ->assertSee(__('gshome.quick_search.up_to', ['value' => "\$90\u{00A0}000"]))
            ->assertSee("50–90\u{00A0}м²")
            ->assertSee(__('gshome.quick_search.bedrooms_short', ['list' => '2, 3']));
    }

    public function test_price_presets_follow_deal(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(QuickSearch::class)
            ->set('deal', 'sale')->assertSeeHtml('data-preset="150000"')->assertDontSeeHtml('data-preset="1200"')
            ->set('deal', 'rent')->assertSeeHtml('data-preset="1200"')->assertDontSeeHtml('data-preset="150000"');
    }

    public function test_compact_mode_shows_summary(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class, ['compact' => true])
            ->set('city_id', 1)->set('price_usd_max', 90000)
            ->assertSeeHtml('data-quick-search-summary')
            ->assertSee('Тбилиси');
    }

    /** Тбилиси (1): Ваке (10) с Ваке-центром (101), Исани-Самгори (20) с Самгори (201). */
    private function fakePlaces(array $searchPosts = []): void
    {
        $row = fn (int $id, string $ru): array => ['id' => $id, 'slug' => (string) $id, 'title_ka' => $ru, 'title_en' => $ru, 'title_ru' => $ru];
        Http::fake(function (Request $request) use ($row): mixed {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return match (true) {
                $path === '/api/v1/dictionaries/districts' => Http::response(['data' => [$row(10, 'Ваке'), $row(20, 'Исани-Самгори')]]),
                $path === '/api/v1/dictionaries/subdistricts' && ($q['district_id'] ?? null) === '10' => Http::response(['data' => [$row(101, 'Ваке-центр')]]),
                $path === '/api/v1/dictionaries/subdistricts' && ($q['district_id'] ?? null) === '20' => Http::response(['data' => [$row(201, 'Самгори')]]),
                default => null,
            };
        });
        RealEstateFake::fake(searchPosts: $searchPosts);
    }

    public function test_whole_district_beside_foreign_subdistricts_is_refused_before_the_api(): void
    {
        $this->fakePlaces([Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->set('property_type_id', 1)->set('city_id', 1)->set('district_id', [10, 20])->set('subdistrict_id', [201])
            ->call('submit')
            ->assertNotified(__('gshome.search.district_whole_beside_subdistricts', ['district' => 'Ваке']));

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/search-jobs'));
        $this->assertSame(0, ListingSearch::query()->count());
    }

    public function test_apply_place_drops_subdistricts_of_other_districts(): void
    {
        $this->fakePlaces();
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->call('applyPlace', 1, [10], [101, 201])
            ->assertSet('city_id', 1)->assertSet('district_id', [10])->assertSet('subdistrict_id', [101]);
    }

    public function test_apply_place_survives_garbage(): void
    {
        $this->fakePlaces();
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->call('applyPlace', 'abc', 'x', [['y']])
            ->assertSet('city_id', null)->assertSet('district_id', [])->assertSet('subdistrict_id', [])
            ->call('applyPlace', '1', [10, 'z', ['q']], null)
            ->assertSet('city_id', 1)->assertSet('district_id', [10])->assertSet('subdistrict_id', []);
    }

    public function test_forged_bedrooms_are_normalised(): void
    {
        $this->fakePlaces();
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->set('bedrooms', [2, ['x'], '9', '5'])
            ->assertSet('bedrooms', ['2', '5'])
            ->assertOk();
    }

    public function test_base_mode_hints_when_place_is_wider_than_selected(): void
    {
        $this->fakePlaces();
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->set('mode', 'base')->set('city_id', 1)->set('district_id', [10])
            ->assertDontSee(__('gshome.quick_search.base_place_wider'))
            ->set('district_id', [10, 20])
            ->assertSee(__('gshome.quick_search.base_place_wider'))
            ->set('district_id', [10])->set('subdistrict_id', [101])
            ->assertSee(__('gshome.quick_search.base_place_wider'))
            ->set('mode', 'fresh')
            ->assertDontSee(__('gshome.quick_search.base_place_wider'));
    }

    public function test_two_directions_default_to_sale_on_fresh_mount(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(QuickSearch::class)->assertSet('deal', 'sale');
    }
}
