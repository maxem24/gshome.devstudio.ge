<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Filament\Pages\Listings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

        Livewire::test(Listings::class)
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
            ->callAction('showGrid')
            ->assertSet('display', 'grid')
            ->assertSee('Первая')->assertSee('Вторая')
            ->assertSee('https://re.test/storage/1_t.webp', escape: false);
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
            ->assertActionVisible('showGrid')->assertActionHidden('showList')
            ->callAction('showGrid')
            ->assertActionVisible('showList')->assertActionHidden('showGrid');
    }

    public function test_renting_lead_never_sends_sale_even_with_forged_filter(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->teamLead(direction: Direction::Rent)->create());

        Livewire::test(Listings::class)->set('tableFilters.direction.value', 'sale');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['deal_type_id'] ?? null) === '2');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/listings?') && str_contains((string) ($this->apiQuery($r)['deal_type_id'] ?? ''), '1'));
    }

    public function test_direction_filter_only_for_both_directions(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->teamLead()->create());
        Livewire::test(Listings::class)->assertTableFilterHidden('direction');

        $this->actingAs(User::factory()->teamLead()->create(['both_directions' => true]));
        Livewire::test(Listings::class)->assertTableFilterVisible('direction');
    }

    public function test_filter_becomes_api_parameter(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->filterTable('rooms', ['2', '3']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['rooms'] ?? null) === '2,3');
    }

    public function test_next_page_sends_api_cursor(): void
    {
        RealEstateFake::fake([
            RealEstateFake::page([RealEstateFake::listing(['id' => 1])], next: 'NEXT1'),
            RealEstateFake::page([RealEstateFake::listing(['id' => 2])], prev: 'PREV1'),
        ]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->call('setPage', 'NEXT1', 'page');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['cursor'] ?? null) === 'NEXT1');
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

        $component = Livewire::test(Listings::class)->call('setPage', 'NEXT1', 'page');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['cursor'] ?? null) === 'NEXT1');

        Http::fake(fn () => Http::response(RealEstateFake::page([])));
        $component->filterTable('sort', 'price_desc');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['sort'] ?? null) === '-price_usd' && ! isset($this->apiQuery($r)['cursor']));

        $component->call('setPage', 'NEXT2', 'page')->searchTable('Ваке');
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

    public function test_cursor_in_url_goes_to_the_api(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing(['id' => 2])], prev: 'PREV1')]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::withQueryParams(['page' => 'CURSOR1'])->test(Listings::class)->assertSet('paginators.page', 'CURSOR1');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?') && ($this->apiQuery($r)['cursor'] ?? null) === 'CURSOR1');
    }

    public function test_numeric_page_in_url_is_not_a_cursor(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::withQueryParams(['page' => '3'])->test(Listings::class);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/listings?') && isset($this->apiQuery($r)['cursor']));
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
}
