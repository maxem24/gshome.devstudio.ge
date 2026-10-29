<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Filament\Pages\MySearches;
use App\Filament\Pages\SearchResult;
use App\Jobs\PollListingSearch;
use App\Models\Company;
use App\Models\ListingSearch;
use App\Models\User;
use App\Notifications\SiteSearchFinished;
use App\RealEstate\SiteSearchRequest;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class MySearchesTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $nino;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        $company = Company::factory()->create();
        $this->lead = User::factory()->teamLead($company, Direction::Sale)->create(['name' => 'Лидия Лидова']);
        $this->nino = User::factory()->agent($this->lead)->create(['name' => 'Нино Агентова']);
        $this->other = User::factory()->agent(User::factory()->teamLead($company, Direction::Sale)->create())->create(['name' => 'Чужой Агентов']);
    }

    /** @param  array<string, mixed>  $attrs */
    private function search(User $by, array $attrs = []): ListingSearch
    {
        return ListingSearch::query()->create($attrs + [
            'user_id' => $by->id,
            'filters' => ['deal' => 'sale', 'city_id' => 1, 'property_type_id' => 1, 'price_usd_max' => 90000],
            'body_hash' => sha1((string) $by->id.microtime()),
            'remote_id' => (string) Str::uuid(),
            'status' => 'done',
            'message' => null,
            'sites' => ['myhome_ge' => ['status' => 'done', 'found' => 2, 'message' => 'готово']],
            'found' => 2,
            'listing_ids' => [13, 14],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function test_list_and_result_show_shown_count_with_found_on_sites_secondary(): void
    {
        $search = $this->search($this->nino, [
            'found' => 250, 'truncated' => true, 'listing_ids' => range(1, 100),
            'sites' => ['myhome_ge' => ['status' => 'paused', 'found' => 250], 'ss_ge' => ['status' => 'importing', 'found' => 0]],
        ]);
        RealEstateFake::fake();
        $this->actingAs($this->nino);

        Livewire::test(MySearches::class)
            ->assertSee('Найдено на сайтах: 250, показаны 100 самых свежих')
            ->assertSee('на паузе')
            ->assertSee('сохраняем');
        $sites = MySearches::sites($search);
        $this->assertSame(['myhome.ge', 'на паузе', 250, 'warning'], [$sites[0]['name'], $sites[0]['label'], $sites[0]['found'], $sites[0]['color']]);
        $this->assertSame(['ss.ge', 'сохраняем', 0, 'info'], [$sites[1]['name'], $sites[1]['label'], $sites[1]['found'], $sites[1]['color']]);
        Livewire::test(SearchResult::class, ['search' => $search->id])
            ->assertSee('Найдено на сайтах: 250, показаны 100 самых свежих');
    }

    public function test_agent_sees_only_own_searches(): void
    {
        $mine = $this->search($this->nino, ['message' => 'мой поиск готов']);
        $theirs = $this->search($this->other, ['message' => 'чужой поиск готов']);
        $this->actingAs($this->nino);

        Livewire::test(MySearches::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertSee('мой поиск готов')
            ->assertSee('myhome.ge');
    }

    public function test_team_lead_sees_own_agents_but_not_other_teams(): void
    {
        $nino = $this->search($this->nino);
        $own = $this->search($this->lead);
        $theirs = $this->search($this->other);
        $this->actingAs($this->lead);

        Livewire::test(MySearches::class)
            ->assertCanSeeTableRecords([$nino, $own])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertSee('Нино Агентова');
    }

    public function test_again_starts_a_new_search_with_same_filters_for_the_author(): void
    {
        Queue::fake();
        $old = $this->search($this->nino, ['finished_at' => now()->subHours(2)]);
        RealEstateFake::fake(searchPosts: [Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $this->actingAs($this->nino);

        Livewire::test(MySearches::class)
            ->callAction(TestAction::make('again')->table($old));

        $new = ListingSearch::query()->where('id', '!=', $old->id)->sole();
        $this->assertEqualsCanonicalizing($old->filters, $new->filters);
        $this->assertSame($this->nino->id, $new->user_id);
        $this->assertSame('queued', $new->status);
        Queue::assertPushed(PollListingSearch::class);
    }

    public function test_again_returns_running_search_instead_of_creating_one(): void
    {
        Queue::fake();
        $running = $this->search($this->nino, ['status' => 'running', 'finished_at' => null, 'listing_ids' => null]);
        // тот же хэш, что вычислит SiteSearches::start для этих фильтров
        RealEstateFake::fake(searchPosts: [Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $this->actingAs($this->nino);
        $hash = sha1((string) json_encode(SiteSearchRequest::build($this->nino, $running->filters)));
        $running->update(['body_hash' => $hash]);

        Livewire::test(MySearches::class)->callAction(TestAction::make('again')->table($running));

        $this->assertSame(1, ListingSearch::query()->count());
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_leader_cannot_repeat_an_agents_search(): void
    {
        $nino = $this->search($this->nino);
        RealEstateFake::fake();
        $this->actingAs($this->lead);

        Livewire::test(MySearches::class)
            ->assertActionHidden(TestAction::make('again')->table($nino))
            ->call('searchAgain', $nino->id)
            ->assertForbidden();
        $this->assertSame(1, ListingSearch::query()->count());
    }

    public function test_foreign_search_id_cannot_be_repeated(): void
    {
        $theirs = $this->search($this->other);
        RealEstateFake::fake();
        $this->actingAs($this->nino);

        Livewire::test(MySearches::class)->call('searchAgain', $theirs->id)->assertNotFound();
    }

    public function test_result_of_foreign_search_is_404(): void
    {
        $theirs = $this->search($this->other);
        RealEstateFake::fake();
        $this->actingAs($this->nino);

        $this->get(SearchResult::getUrl(['search' => $theirs->id]))->assertNotFound();
        $this->get(SearchResult::getUrl(['search' => 999999]))->assertNotFound();
    }

    public function test_result_shows_cards_and_hides_other_direction_with_count(): void
    {
        $search = $this->search($this->nino, ['listing_ids' => [13, 14, 99], 'found' => 3]);
        // Направление отбирает API (deal_type_id поиска), снятые — в meta.missing_ids.
        $page = RealEstateFake::page([RealEstateFake::listing(['id' => 13, 'title' => ['ru' => 'Квартира в Ваке']])]);
        $page['meta']['missing_ids'] = [99];
        RealEstateFake::fake([$page]);
        $this->actingAs($this->nino);

        Livewire::test(SearchResult::class, ['search' => $search->id])
            ->assertSee('Квартира в Ваке')
            ->assertSet('hidden', 1)
            ->assertSee('Сняты с сайтов: 1');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/listings?')
            && $request['ids'] === '13,14,99' && $request['deal_type_id'] === '1' && $request['per_page'] === (string) SearchResult::PER_PAGE);
    }

    public function test_result_pages_through_api_cursors_and_sorts_on_the_server(): void
    {
        $search = $this->search($this->nino, ['listing_ids' => range(1, 30), 'found' => 30]);
        RealEstateFake::fake([
            RealEstateFake::page([RealEstateFake::listing(['id' => 1, 'title' => ['ru' => 'Первая']])], next: 'c2'),
            RealEstateFake::page([RealEstateFake::listing(['id' => 25, 'title' => ['ru' => 'Двадцать пятая']])], prev: 'c1'),
            RealEstateFake::page([RealEstateFake::listing(['id' => 7, 'title' => ['ru' => 'Самая дешёвая']])]),
        ]);
        $this->actingAs($this->nino);

        Livewire::test(SearchResult::class, ['search' => $search->id])
            ->assertSee('Первая')
            ->assertSee('1–1')
            ->assertDontSee('1–1 из')
            ->call('nextResultPage')
            ->assertSet('page', 2)
            ->assertSee('Двадцать пятая')
            // Последняя страница: всего столько, сколько дошло до неё.
            ->assertSee('25–25 из 25')
            ->set('sort', 'price_asc')
            ->assertSet('page', 1)
            ->assertSet('cursor', null)
            ->assertSee('Самая дешёвая');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'cursor=c2'));
        Http::assertSent(fn ($request): bool => ($request['sort'] ?? null) === 'price_usd' && ! str_contains($request->url(), 'cursor='));
    }

    public function test_result_marks_partial_cards(): void
    {
        $search = $this->search($this->nino, ['listing_ids' => [13]]);
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing(['id' => 13, 'partial' => true])])]);
        $this->actingAs($this->nino);

        Livewire::test(SearchResult::class, ['search' => $search->id])->assertSee('неполная карточка');
    }

    public function test_running_search_shows_status_and_polls_without_calling_the_api(): void
    {
        $search = $this->search($this->nino, [
            'status' => 'running', 'message' => 'Поиск идёт, обычно 1–3 мин', 'finished_at' => null, 'listing_ids' => null, 'found' => 0,
            'sites' => ['myhome_ge' => ['status' => 'running', 'found' => 0, 'message' => 'Поиск идёт']],
        ]);
        RealEstateFake::fake();
        $this->actingAs($this->nino);

        Livewire::test(SearchResult::class, ['search' => $search->id])
            ->assertSee('Поиск идёт, обычно 1–3 мин')
            ->assertSee('идёт')
            ->assertSeeHtml('wire:poll.10s');
        // Справочник типа для подписи фильтров — можно; объявлений, пока поиск идёт, не читаем.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/listings'));
    }

    public function test_finished_search_does_not_poll_and_flags_partial(): void
    {
        $search = $this->search($this->nino, [
            'listing_ids' => [],
            'sites' => ['myhome_ge' => ['status' => 'done', 'found' => 2], 'ss_ge' => ['status' => 'failed', 'found' => 0]],
        ]);
        RealEstateFake::fake();
        $this->actingAs($this->nino);

        Livewire::test(SearchResult::class, ['search' => $search->id])
            ->assertDontSeeHtml('wire:poll.10s')
            ->assertSee('результат неполный');
    }

    public function test_notification_links_to_the_result_route(): void
    {
        $search = $this->search($this->nino);

        $this->assertTrue(Route::has(SiteSearchFinished::RESULT_ROUTE));
        $this->assertSame(SearchResult::getUrl(['search' => $search->id]), route(SiteSearchFinished::RESULT_ROUTE, $search));
        $data = (new SiteSearchFinished($search))->toDatabase($this->nino);
        $this->assertSame(SearchResult::getUrl(['search' => $search->id]), $data['actions'][0]['url']);
    }

    public function test_subdistrict_note_shows_once_on_the_page_and_in_the_notification(): void
    {
        $note = 'На myhome нет микрорайона «Шоссе Аэропорта» (Исани-Самгори) — по нему там не искали.';
        $search = $this->search($this->nino, [
            'message' => "Поиск завершён. {$note}",
            'sites' => [
                'myhome_ge' => ['status' => 'done', 'found' => 40, 'pages_done' => 5, 'message' => "Готово. {$note}", 'note' => $note],
                'ss_ge' => ['status' => 'done', 'found' => 80, 'pages_done' => 5, 'message' => 'Готово'],
            ],
        ]);

        $sites = MySearches::sites($search);
        $this->assertSame('Готово.', $sites[0]['message']);
        $this->assertSame('Готово', $sites[1]['message']);
        $this->assertSame([$note], $search->siteNotes());

        $data = (new SiteSearchFinished($search))->toDatabase($this->nino);
        $this->assertStringEndsWith($note, $data['body']);
    }

    public function test_pages_open_from_the_menu(): void
    {
        RealEstateFake::fake();
        $this->actingAs($this->nino);

        $this->get('/admin/my-searches')->assertOk()->assertSee('Мои поиски');
    }

    public function test_zero_shown_with_found_explains_filters_on_page_and_in_notification(): void
    {
        $search = $this->search($this->nino, [
            'filters' => ['city_id' => 1, 'price_usd_max' => 500, 'rooms' => [2]],
            'found' => 80, 'listing_ids' => [],
        ]);
        $this->actingAs($this->nino);
        $text = "На сайтах найдено 80, но ни одно не прошло ваши фильтры: Продажа · ≤\u{00A0}500\u{00A0}$ · 2 комн.";
        RealEstateFake::fake();

        Livewire::test(SearchResult::class, ['search' => $search->id])->assertSee($text);
        Livewire::test(MySearches::class)->assertSee("Продажа · ≤\u{00A0}500\u{00A0}$ · 2 комн.");
        $data = (new SiteSearchFinished($search))->toDatabase($this->nino);
        $this->assertSame($text, $data['body']);

        $found = $this->search($this->nino, ['found' => 0, 'listing_ids' => []]);
        $this->assertFalse($found->zeroButFound());
    }

    public function test_result_shows_rent_cards_for_the_sale_agents_own_rent_search(): void
    {
        $search = $this->search($this->nino, ['filters' => ['deal' => 'rent', 'city_id' => 1], 'listing_ids' => [14], 'found' => 1]);
        RealEstateFake::fake([RealEstateFake::page([
            RealEstateFake::listing([
                'id' => 14,
                'deal_type' => ['id' => 2, 'slug' => 'rent', 'title' => 'Сдаётся'],
                'title' => ['ru' => 'Аренда в Сабуртало'],
            ]),
        ])]);
        $this->actingAs($this->nino);

        Livewire::test(SearchResult::class, ['search' => $search->id])
            ->assertSee('Аренда в Сабуртало')
            ->assertSee('/listings/14?search='.$search->id, escape: false)
            ->assertSet('hidden', 0);

        // Сделка поиска — аренда: API отбирает её, а не направление агента (продажа).
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/listings?') && $request['deal_type_id'] === '2');
    }

    public function test_missing_deal_type_is_an_incomplete_list_not_every_direction(): void
    {
        $search = $this->search($this->nino, ['listing_ids' => [13], 'found' => 1]);
        Http::fake(fn ($request) => str_contains($request->url(), '/dictionaries/deal-types')
            ? Http::response(['data' => [['id' => 2, 'slug' => 'rent', 'title_ka' => 'ქ', 'title_en' => 'Rent', 'title_ru' => 'Сдаётся']]])
            : null);
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing(['id' => 13, 'title' => ['ru' => 'Не должна показаться']])])]);
        $this->actingAs($this->nino);

        Livewire::test(SearchResult::class, ['search' => $search->id])
            ->assertOk()
            ->assertSet('incomplete', true)
            ->assertDontSee('Не должна показаться');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/listings?'));
    }

    public function test_back_from_a_card_returns_to_the_same_page_and_order(): void
    {
        $search = $this->search($this->nino, ['listing_ids' => range(1, 60), 'found' => 60]);
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing(['id' => 49, 'title' => ['ru' => 'С третьей страницы']])], prev: 'c2')]);
        $this->actingAs($this->nino);

        // Адрес, на который ведёт «назад»: ?sort=…&p=3&c=…
        Livewire::withQueryParams(['sort' => 'price_asc', 'p' => 3, 'c' => 'c3'])
            ->test(SearchResult::class, ['search' => $search->id])
            ->assertSet('page', 3)
            ->assertSet('cursor', 'c3')
            ->assertSee('С третьей страницы')
            ->assertSee('49–49 из 49');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'cursor=c3') && ($request['sort'] ?? null) === 'price_usd');
    }

    public function test_page_without_cursor_in_the_address_is_the_first_page(): void
    {
        $search = $this->search($this->nino, ['listing_ids' => [13], 'found' => 1]);
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing(['id' => 13])])]);
        $this->actingAs($this->nino);

        Livewire::withQueryParams(['p' => 7])
            ->test(SearchResult::class, ['search' => $search->id])
            ->assertSet('page', 1)
            ->assertSet('cursor', null);
    }
}
