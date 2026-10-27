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

    public function test_rejected_district_shows_search_specific_message(): void
    {
        RealEstateFake::fake(searchPosts: [Http::response(['error' => ['code' => 'validation_failed', 'fields' => ['district_id' => 'Район 9 не найден']]], 422)]);
        $this->actingAs($this->seller());

        Livewire::test(FindListing::class)
            ->callAction('find', ['city_id' => 1])
            ->assertNotified(__('gshome.search.rejected_district'))
            ->assertActionMounted('find');
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

    public function test_single_direction_agent_has_no_deal_field(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->seller());
        Livewire::test(FindListing::class)->mountAction('find')->assertSchemaComponentHidden('deal', 'mountedActionSchema0');

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(FindListing::class)->mountAction('find')->assertSchemaComponentVisible('deal', 'mountedActionSchema0');
    }
}
