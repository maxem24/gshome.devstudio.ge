<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Enums\ListingScope;
use App\Filament\Pages\Listings;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingsPageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> параметры запроса к API (GET — в адресе, не в теле) */
    private function apiQuery(Request $r): array
    {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
    }

    public function test_every_role_opens_the_page(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing()])]);
        $lead = User::factory()->teamLead()->create();

        foreach ([User::factory()->owner()->create(), User::factory()->companyOwner()->create(), $lead, User::factory()->agent($lead)->create()] as $user) {
            $this->actingAs($user)->get('/admin/listings')->assertOk();
        }
    }

    public function test_list_shows_listing_with_label(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing(['title' => ['ru' => 'Светлая квартира']])])]);
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::withQueryParams(['display' => 'list'])->test(Listings::class)
            ->assertSet('display', 'list')
            ->assertSee('Светлая квартира')
            ->assertSee('$90 000')
            ->assertSee('Другая компания')
            ->assertSee('Собственник');
    }

    public function test_grid_and_list_show_the_same_records(): void
    {
        RealEstateFake::fake([RealEstateFake::page([
            RealEstateFake::listing(['id' => 1, 'title' => ['ru' => 'Первая']]),
            RealEstateFake::listing(['id' => 2, 'title' => ['ru' => 'Вторая']]),
        ])]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->assertSee('Первая')->assertSee('Вторая')
            ->assertSet('display', 'grid')
            ->assertSee('https://re.test/storage/1_t.webp', escape: false)
            ->callAction('showList')
            ->assertSet('display', 'list')
            ->assertSee('Первая')->assertSee('Вторая')
            ->callAction('showGrid')
            ->assertSet('display', 'grid')
            ->assertSee('Первая')->assertSee('Вторая');
    }

    public function test_grid_from_url_renders_grid_columns(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing(['id' => 1])])]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::withQueryParams(['display' => 'grid'])->test(Listings::class)
            ->assertSet('display', 'grid')
            ->assertSee('https://re.test/storage/1_t.webp', escape: false);
    }

    public function test_display_switch_actions(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)
            ->assertSet('display', 'grid')
            ->assertSeeHtml('x-show="areFiltersOpen"')
            ->assertSeeHtml('fi-ta-filters-trigger-action-ctn')
            ->assertSee(__('gshome.listings.direction'))
            ->assertActionHidden('showGrid')->assertActionVisible('showList')
            ->callAction('showList')
            ->assertActionVisible('showGrid')->assertActionHidden('showList');
    }

    public function test_renting_lead_never_sends_sale_even_with_forged_filter(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->teamLead(direction: Direction::Rent)->create());

        Livewire::test(Listings::class)->set('tableFilters.direction.value', 'sale');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['deal_type_id'] ?? null) === '2');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/listings?') && str_contains((string) ($this->apiQuery($r)['deal_type_id'] ?? ''), '1'));
    }

    public function test_sales_lead_never_sends_rent_even_with_forged_filter(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(Listings::class)
            ->assertTableFilterHidden('direction')
            ->set('tableFilters.direction.value', 'rent');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['deal_type_id'] ?? null) === '1');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/listings?') && str_contains((string) ($this->apiQuery($r)['deal_type_id'] ?? ''), '2'));
    }

    public function test_direction_filter_for_both_directions_and_people_without_a_team(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->teamLead()->create());
        Livewire::test(Listings::class)->assertTableFilterHidden('direction');

        foreach ([
            User::factory()->teamLead()->create(['both_directions' => true]),
            User::factory()->owner()->create(),
            User::factory()->companyOwner()->create(),
        ] as $user) {
            RealEstateFake::fake();
            $this->actingAs($user);

            Livewire::test(Listings::class)
                ->assertTableFilterVisible('direction')
                ->set('tableFilters.direction.value', 'rent');

            Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['deal_type_id'] ?? null) === '2');
        }
    }

    public function test_filters_return_from_the_session_without_a_query_string(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->filterTable('rooms', ['2', '3']);
        $this->assertNotNull(session(Listings::filtersSessionKey()));

        RealEstateFake::fake();
        Livewire::test(Listings::class)->assertSet('tableFilters.rooms.values', ['2', '3']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['rooms'] ?? null) === '2,3');
    }

    public function test_url_filters_win_over_the_session(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());
        session()->put(Listings::filtersSessionKey(), ['rooms' => ['values' => ['1']]]);

        Livewire::withQueryParams(['filters' => ['rooms' => ['values' => ['2', '3']]]])->test(Listings::class)
            ->assertSet('tableFilters.rooms.values', ['2', '3']);
    }

    public function test_reset_forgets_remembered_filters(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)
            ->filterTable('rooms', ['2', '3'])
            ->call('resetTableFiltersForm');

        RealEstateFake::fake();
        Livewire::test(Listings::class);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/listings?') && isset($this->apiQuery($r)['rooms']));
    }

    public function test_new_login_forgets_remembered_filters(): void
    {
        RealEstateFake::fake();
        $user = User::factory()->owner()->create();
        $this->actingAs($user);

        Livewire::test(Listings::class)->filterTable('rooms', ['2', '3']);
        $this->assertNotNull(session(Listings::filtersSessionKey()));

        Auth::guard('web')->logout();
        Auth::guard('web')->login($user);
        $this->assertNull(session(Listings::filtersSessionKey()));

        RealEstateFake::fake();
        Livewire::test(Listings::class);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/listings?') && isset($this->apiQuery($r)['rooms']));
    }

    public function test_filter_becomes_api_parameter(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->filterTable('rooms', ['2', '3']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['rooms'] ?? null) === '2,3');
    }

    public function test_load_more_appends_the_next_api_cursor(): void
    {
        RealEstateFake::fake([
            RealEstateFake::page([RealEstateFake::listing(['id' => 1, 'title' => ['ru' => 'Первый']])], next: 'NEXT1'),
            RealEstateFake::page([RealEstateFake::listing(['id' => 2, 'title' => ['ru' => 'Второй']])], prev: 'PREV1'),
        ]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)
            ->assertSee('Первый')
            ->assertSeeHtml('wire:target="loadMoreListings"')
            ->assertSeeHtml('fi-loading-indicator')
            ->call('loadMoreListings')
            ->assertSee('Первый')
            ->assertSee('Второй');

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), '/listings?'))->values();
        $this->assertCount(2, $sent);
        $this->assertArrayNotHasKey('cursor', $this->apiQuery($sent[0][0]));
        $this->assertSame('NEXT1', $this->apiQuery($sent[1][0])['cursor']);
    }

    public function test_unavailable_api_gives_empty_list_and_notice(): void
    {
        Http::fake(['*/dictionaries/*' => Http::response(['data' => RealEstateFake::dealTypes()]), '*' => Http::response([], 503)]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)
            ->assertOk()
            ->assertNotified('Real Estate недоступен, попробуйте позже.');
    }

    public function test_phone_filters_hidden_when_disabled(): void
    {
        RealEstateFake::configure(phoneFilters: false);
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)
            ->assertTableFilterHidden('ownership')
            ->assertTableFilterHidden('employee');
    }

    public function test_changing_filter_or_search_drops_the_cursor(): void
    {
        RealEstateFake::fake([
            RealEstateFake::page([RealEstateFake::listing(['id' => 1])], next: 'NEXT1'),
            RealEstateFake::page([RealEstateFake::listing(['id' => 2])], prev: 'PREV1'),
        ]);
        $this->actingAs(User::factory()->owner()->create());

        $component = Livewire::test(Listings::class)->call('loadMoreListings');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['cursor'] ?? null) === 'NEXT1');

        Http::fake(fn () => Http::response(RealEstateFake::page([])));
        $component->filterTable('sort', 'price_desc');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['sort'] ?? null) === '-price_usd' && ! isset($this->apiQuery($r)['cursor']));

        $component->searchTable('Ваке');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['q'] ?? null) === 'Ваке' && ! isset($this->apiQuery($r)['cursor']));
    }

    public function test_row_links_to_card(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing()])]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->assertSee('/admin/listings/13', escape: false);
    }

    public function test_filters_and_search_come_from_the_url(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::withQueryParams(['filters' => ['rooms' => ['values' => ['2', '3']]], 'search' => 'Ваке'])->test(Listings::class)
            ->assertSet('tableFilters.rooms.values', ['2', '3'])
            ->assertSet('tableSearch', 'Ваке');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?')
            && ($this->apiQuery($r)['rooms'] ?? null) === '2,3' && ($this->apiQuery($r)['q'] ?? null) === 'Ваке');
    }

    public function test_garbage_in_url_filters_does_not_break_the_page(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        foreach ([
            'filters[rooms][values]=x',
            'filters[rooms]=x',
            'filters[price]=x',
            'filters[price][min][]=1',
            'filters[direction][value][]=sale',
            'filters[location][city_id][]=1',
            'filters[owners_only]=1',
            'filters[created][from][]=x',
            'filters[created][from]=not-a-date',
            'filters[rooms][values][]=x&filters[rooms][values][1][]=y',
        ] as $query) {
            $this->get('/admin/listings?'.$query)->assertOk();
        }
    }

    public function test_array_search_in_url_does_not_break_the_page(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        foreach (['search[]=x', 'search[a][b]=x', 'filters[employee][value]=abc', 'filters[location][city_id]=abc', 'filters[source][value]=evil'] as $query) {
            $this->get('/admin/listings?'.$query)->assertOk();
        }
        Livewire::withQueryParams(['search' => ['x']])->test(Listings::class)->assertSet('tableSearch', '');
        Livewire::test(Listings::class)->set('tableSearch', ['x'])->assertOk();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/dictionaries/districts'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/listings?') && isset($this->apiQuery($r)['q']));
    }

    public function test_page_query_is_not_sent_as_a_cursor(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::withQueryParams(['page' => 'CURSOR1'])->test(Listings::class);
        Livewire::withQueryParams(['page' => '3'])->test(Listings::class);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/listings?') && isset($this->apiQuery($r)['cursor']));
    }

    public function test_stale_cursor_restarts_from_the_first_page_without_error_log(): void
    {
        Log::swap($log = \Mockery::spy(LogManager::class));
        $this->actingAs(User::factory()->owner()->create());
        $stale = Http::response(['error' => ['code' => 'validation_failed', 'fields' => ['cursor' => ['bad']]]], 422);
        $first = RealEstateFake::page([RealEstateFake::listing(['id' => 1, 'title' => ['ru' => 'Первая']])], next: 'NEXT1');
        $restart = RealEstateFake::page([RealEstateFake::listing(['id' => 77, 'title' => ['ru' => 'Снова']])]);
        $plain = 0;
        Http::fake(function (Request $r) use ($stale, $first, $restart, &$plain) {
            return match (true) {
                str_contains($r->url(), '/dictionaries/deal-types') => Http::response(['data' => RealEstateFake::dealTypes()]),
                str_contains($r->url(), '/dictionaries/') => Http::response(['data' => [['id' => 1, 'slug' => 'tbilisi', 'title_ru' => 'Тбилиси']]]),
                isset($this->apiQuery($r)['cursor']) => $stale,
                default => $plain++ === 0 ? Http::response($first) : Http::response($restart),
            };
        });

        Livewire::withQueryParams(['filters' => ['source' => ['value' => 'ss_ge']]])->test(Listings::class)
            ->call('loadMoreListings')
            ->assertOk()
            ->assertNotNotified()
            ->assertDontSee('Первая')
            ->assertSee('Снова');

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), '/listings?'))->values();
        $this->assertCount(3, $sent);
        $this->assertArrayNotHasKey('cursor', $this->apiQuery($sent[0][0]));
        $this->assertSame('NEXT1', $this->apiQuery($sent[1][0])['cursor']);
        $this->assertArrayNotHasKey('cursor', $this->apiQuery($sent[2][0]));
        // Повтор не теряет ни фильтры, ни направление: отличается от запроса с курсором только его отсутствием.
        $this->assertSame('ss_ge', $this->apiQuery($sent[2][0])['source']);
        $this->assertSame(Arr::except($this->apiQuery($sent[1][0]), 'cursor'), $this->apiQuery($sent[2][0]));
        $log->shouldNotHaveReceived('error');
    }

    public function test_array_table_search_set_through_wire_does_not_break_the_next_request(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)
            ->set('tableSearch', ['x'])
            ->call('$refresh')
            ->assertOk()
            ->assertSet('tableSearch', '');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/listings?') && isset($this->apiQuery($r)['q']));
    }

    public function test_search_box_is_available_in_grid_view(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::withQueryParams(['display' => 'grid'])->test(Listings::class)
            ->assertSeeHtml('wire:model.live.debounce.500ms="tableSearch"')
            ->searchTable('Ваке')
            ->assertSet('tableSearch', 'Ваке')
            ->searchTable('')
            ->assertSet('tableSearch', '');
    }

    public function test_changing_city_resets_district(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)
            ->set('tableFilters.location.district_id', '3')
            ->set('tableFilters.location.city_id', '1')
            ->assertSet('tableFilters.location.district_id', null);
    }

    public function test_sort_filter_explains_hidden_listings(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->assertSee('При сортировке по цене или площади объявления без них не показываются');
    }

    public function test_company_owner_sees_the_company_they_represent(): void
    {
        RealEstateFake::fake();
        $user = User::factory()->companyOwner()->create();

        $this->actingAs($user)->get('/admin/listings')
            ->assertOk()
            ->assertSee($user->name.' · '.$user->company->name)
            ->assertSee(__('gshome.listings.represents', ['name' => $user->company->name]))
            ->assertSee(__('gshome.listings.scope_our_agency'));
    }

    public function test_scope_buttons_narrow_owners_our_agency_and_agencies(): void
    {
        RealEstateFake::fake([RealEstateFake::page([
            RealEstateFake::listing(['id' => 1, 'title' => ['ru' => 'От собственника']]),
            RealEstateFake::listing(['id' => 2, 'title' => ['ru' => 'От агентства'], 'seller' => ['is_owner' => false, 'is_agency' => true, 'agency_name' => 'Дом']]),
        ])]);
        [$company, $partner] = Company::factory()->count(2)->create()->all();
        CompanyExchange::create(['company_a_id' => $company->id, 'company_b_id' => $partner->id, 'enabled' => true]);
        $lead = User::factory()->teamLead($company)->create();
        $ours = Phone::factory()->heldBy($lead)->create()->number;
        $linked = Phone::factory()->create(['company_id' => $partner->id])->number;
        $this->actingAs($lead);

        Livewire::test(Listings::class)
            ->assertSee(__('gshome.listings.scope_owners'))
            ->call('setScope', ListingScope::Owners->value)
            ->assertSet('scope', ListingScope::Owners->value);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/listings?')
            && ($this->apiQuery($r)['is_owner'] ?? null) === '1'
            && ($this->apiQuery($r)['deal_type_id'] ?? null) === '1');

        Livewire::test(Listings::class)->call('setScope', ListingScope::OurAgency->value);
        Http::assertSent(function (Request $r) use ($ours, $linked): bool {
            if (! str_contains($r->url(), '/listings?')) {
                return false;
            }
            $phones = explode(',', (string) ($this->apiQuery($r)['phones'] ?? ''));

            return in_array($ours, $phones, true) && ! in_array($linked, $phones, true);
        });

        Livewire::test(Listings::class)
            ->call('setScope', ListingScope::Agencies->value)
            ->assertSee('От агентства')
            ->assertDontSee('От собственника');
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'is_agency'));
    }

    public function test_my_listings_use_only_the_signed_in_agents_phone(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create();
        $mine = Phone::factory()->heldBy($agent)->create()->number;
        $colleague = Phone::factory()->heldBy($lead)->create()->number;
        RealEstateFake::fake([RealEstateFake::page([
            RealEstateFake::listing(['id' => 1, 'title' => ['ru' => 'Объявление коллеги'], 'contact' => ['phone_e164' => $colleague]]),
            RealEstateFake::listing(['id' => 2, 'title' => ['ru' => 'Объявление агента'], 'contact' => ['phone_e164' => $mine]]),
        ])]);
        $this->actingAs($agent);

        Livewire::test(Listings::class)
            ->call('setScope', ListingScope::Mine->value)
            ->assertSee('Объявление агента')
            ->assertDontSee('Объявление коллеги');

        Http::assertSent(function (Request $r) use ($mine, $colleague): bool {
            if (! str_contains($r->url(), '/listings?')) {
                return false;
            }
            $query = $this->apiQuery($r);
            $phones = (string) ($query['phones'] ?? '');

            return $phones === $mine && ! str_contains($phones, $colleague) && ($query['only_primary'] ?? null) === '0';
        });
    }

    public function test_unknown_scope_in_the_url_falls_back_to_all(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::withQueryParams(['scope' => 'nope'])->test(Listings::class)->assertSet('scope', 'all');
    }
}
