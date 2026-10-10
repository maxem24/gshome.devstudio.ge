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
}
