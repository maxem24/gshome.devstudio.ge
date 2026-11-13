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

            return isset($b['bedrooms']) || isset($b['bedrooms_min'])
                ? ($b['area_min'] ?? null) === 40 && ($b['price_usd_max'] ?? null) === 90000 && isset($b['district_id'])
                : false;
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
            ->assertSeeHtml('disabled')
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
}
