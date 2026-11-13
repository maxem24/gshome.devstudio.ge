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
        $this->actingAs($this->seller());

        Livewire::test(QuickSearch::class)
            ->set('mode', 'base')->set('deal', 'rent')->set('city_id', 1)
            ->call('submit')
            ->assertNotified(__('gshome.search.base_own_only'))
            ->assertNoRedirect();
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

        Livewire::test(QuickSearch::class)
            ->set('property_type_id', null)->set('city_id', null)
            ->assertSeeHtml('<button type="submit" disabled')
            ->call('submit');

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

        Livewire::test(QuickSearch::class)
            ->assertDontSee(__('gshome.quick_search.base_own_only'))
            ->set('deal', 'rent')
            ->assertSeeHtml('value="base" disabled')
            ->assertSee(__('gshome.quick_search.base_own_only'));
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

        Livewire::test(QuickSearch::class)
            ->assertDontSee(__('gshome.quick_search.collectors_offline'))
            ->assertDontSeeHtml('<button type="submit" disabled');
    }
}
