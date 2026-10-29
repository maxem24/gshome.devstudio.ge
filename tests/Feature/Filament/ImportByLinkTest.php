<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Filament\Pages\MySearches;
use App\Filament\Pages\SearchResult;
use App\Jobs\PollListingImport;
use App\Livewire\ImportByLink;
use App\Models\Company;
use App\Models\ListingSearch;
use App\Models\User;
use App\RealEstate\RealEstateClient;
use App\Search\FiltersSummary;
use App\Search\LinkImports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ImportByLinkTest extends TestCase
{
    use RefreshDatabase;

    private const SITES = ['myhome_ge', 'ss_ge'];

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

    // Http::fake копит заглушки (первая побеждает): «есть» и «нет» — в разных тестах.
    public function test_button_is_hidden_until_real_estate_accepts_links(): void
    {
        RealEstateFake::fake([RealEstateFake::page([])]);
        $this->actingAs($this->seller())->get('/admin/listings')->assertOk()->assertDontSee(__('gshome.link.button'));
    }

    public function test_button_is_in_the_header_when_real_estate_accepts_links(): void
    {
        RealEstateFake::fake([RealEstateFake::page([])], imports: self::SITES);
        $this->actingAs($this->seller())->get('/admin/listings')->assertOk()->assertSee(__('gshome.link.button'));
    }

    public function test_short_outage_of_real_estate_keeps_the_button(): void
    {
        RealEstateFake::fake(imports: self::SITES);
        $client = app(RealEstateClient::class);
        $this->assertSame(self::SITES, $client->importSources());

        Cache::forget('real-estate:me:imports');
        Cache::put('real-estate:down', true, 30);

        $this->assertSame(self::SITES, $client->importSources());
    }

    public function test_sites_are_recognised_by_host(): void
    {
        $this->assertSame('myhome_ge', LinkImports::source('https://www.myhome.ge/pr/21345678/'));
        $this->assertSame('ss_ge', LinkImports::source(' https://home.ss.ge/ka/udzravi-qoneba/l/bina '));
        $this->assertNull(LinkImports::source('https://evil-myhome.ge/pr/1'));
        $this->assertNull(LinkImports::source('https://myhome.ge.evil.com/pr/1'));
        $this->assertNull(LinkImports::source('javascript:alert(1)//myhome.ge'));
        $this->assertNull(LinkImports::source('ftp://myhome.ge/pr/1'));
        $this->assertNull(LinkImports::source("https://www.myhome.ge/pr/1/\nhttps://www.myhome.ge/pr/2/"));
        $this->assertNull(LinkImports::source('https://www.myhome.ge/pr/1/ посмотрите'));
    }

    public function test_link_from_another_site_is_refused_before_the_api(): void
    {
        RealEstateFake::fake(imports: self::SITES);
        $this->actingAs($this->seller());

        Livewire::test(ImportByLink::class)
            ->callAction('link', ['url' => 'https://www.zillow.com/homedetails/1'])
            ->assertHasActionErrors(['url']);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/imports'));
    }

    public function test_listing_already_in_the_base_opens_its_card(): void
    {
        RealEstateFake::fake(imports: self::SITES, importPosts: [Http::response(['data' => RealEstateFake::importListing(['status' => 'done', 'listing_id' => 77])], 201)]);
        $user = $this->seller();
        $this->actingAs($user);

        Livewire::test(ImportByLink::class)
            ->callAction('link', ['url' => 'https://www.myhome.ge/pr/21345678/'])
            ->assertRedirect(LinkImports::cardUrl($user, 77));

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/imports?lang=')
            && $r['url'] === 'https://www.myhome.ge/pr/21345678/');
        Queue::assertNothingPushed();
    }

    public function test_new_listing_is_queued_and_polled(): void
    {
        RealEstateFake::fake(imports: self::SITES, importPosts: [Http::response(['data' => RealEstateFake::importListing()], 201)]);
        $user = $this->seller();
        $this->actingAs($user);

        Livewire::test(ImportByLink::class)
            ->callAction('link', ['url' => 'https://www.myhome.ge/pr/21345678/'])
            ->assertHasNoErrors()
            ->assertNotified(__('gshome.link.queued'));

        Queue::assertPushed(PollListingImport::class, fn (PollListingImport $job): bool => $job->importId === '5d1e2f3a-4b5c-4d6e-8f70-112233445566' && $job->userId === $user->id);
    }

    public function test_rejected_link_shows_the_server_text_and_keeps_the_modal(): void
    {
        RealEstateFake::fake(imports: self::SITES, importPosts: [Http::response(['error' => [
            'code' => 'validation_failed', 'message' => 'Проверьте поля.',
            'fields' => ['url' => ['Это профиль агентства, а не объявление и не поиск.']],
        ]], 422)]);
        $this->actingAs($this->seller());

        Livewire::test(ImportByLink::class)
            ->callAction('link', ['url' => 'https://www.myhome.ge/agency/55'])
            ->assertNotified('Это профиль агентства, а не объявление и не поиск.')
            ->assertActionMounted('link');
    }

    public function test_no_collector_online_shows_the_server_message(): void
    {
        RealEstateFake::fake(imports: self::SITES, importPosts: [Http::response(['error' => [
            'code' => 'no_collector', 'message' => 'Сборщик myhome сейчас не на связи — попробуйте через несколько минут.',
        ]], 503)]);
        $this->actingAs($this->seller());

        Livewire::test(ImportByLink::class)
            ->callAction('link', ['url' => 'https://www.myhome.ge/pr/21345678/'])
            ->assertNotified('Сборщик myhome сейчас не на связи — попробуйте через несколько минут.')
            ->assertActionMounted('link');
    }

    public function test_busy_collectors_say_so_like_the_refresh_button(): void
    {
        RealEstateFake::fake(imports: self::SITES, importPosts: [Http::response(['error' => [
            'code' => 'refresh_busy', 'message' => 'Too many cards queued.',
        ]], 503, ['Retry-After' => '30'])]);
        $this->actingAs($this->seller());

        Livewire::test(ImportByLink::class)
            ->callAction('link', ['url' => 'https://www.myhome.ge/pr/21345678/'])
            ->assertNotified(__('gshome.listings.refresh.busy'))
            ->assertActionMounted('link');
    }

    public function test_search_link_becomes_a_search_with_the_link_deal(): void
    {
        RealEstateFake::fake(imports: self::SITES, importPosts: [Http::response(['data' => RealEstateFake::importSearch()], 202)]);
        $user = $this->seller();
        $this->actingAs($user);

        $component = Livewire::test(ImportByLink::class)
            ->callAction('link', ['url' => 'https://home.ss.ge/ka/udzravi-qoneba/l/bina/qiravdeba?cityIdList=95&withPets=true']);

        $search = ListingSearch::query()->where('user_id', $user->id)->sole();
        $component->assertRedirect(SearchResult::getUrl(['search' => $search->id]));
        $this->assertSame('0b0e7d3a-5c1f-4a53-9a55-3a1f0c9d2e11', $search->remote_id);
        $this->assertSame('rent', $search->filters['deal']);
        $this->assertSame('https://home.ss.ge/ka/udzravi-qoneba/l/bina/qiravdeba?cityIdList=95', $search->filters['link']);
        // Агент продаж видит результат в аренде: так понята ссылка.
        $this->assertSame([Direction::Rent], $search->directions());
        $this->assertStringStartsWith(__('gshome.link.summary', ['site' => 'ss.ge']), FiltersSummary::make($search->filters, $user));
    }

    public function test_same_link_while_its_search_runs_does_not_post_again(): void
    {
        RealEstateFake::fake(imports: self::SITES, importPosts: [Http::response(['data' => RealEstateFake::importSearch()], 202)]);
        $this->actingAs($this->seller());
        $url = 'https://home.ss.ge/ka/udzravi-qoneba/l/bina/qiravdeba?cityIdList=95';

        Livewire::test(ImportByLink::class)->callAction('link', ['url' => $url])->assertNotified(__('gshome.search.started'));
        Livewire::test(ImportByLink::class)->callAction('link', ['url' => $url])->assertNotified(__('gshome.search.started_running'));

        Http::assertSentCount(1 + $this->nonImportRequests());
    }

    /** Кроме POST /imports, в тесте ходят только за справочником сделок и /me: их число не важно, важно, что POST один. */
    private function nonImportRequests(): int
    {
        return count(Http::recorded(fn (Request $r): bool => ! str_contains($r->url(), '/imports')));
    }

    public function test_again_sends_the_pasted_link_so_a_running_search_is_not_posted_twice(): void
    {
        $pasted = 'https://home.ss.ge/ka/udzravi-qoneba/l/bina/qiravdeba?cityIdList=95&utm_source=fb';
        RealEstateFake::fake(imports: self::SITES, importPosts: [Http::response(['data' => RealEstateFake::importSearch()], 202)]);
        $user = $this->seller();
        $this->actingAs($user);

        Livewire::test(ImportByLink::class)->callAction('link', ['url' => $pasted]);
        $search = ListingSearch::query()->where('user_id', $user->id)->sole();
        $this->assertSame($pasted, $search->filters['link_sent']);

        Livewire::test(MySearches::class)->call('searchAgain', $search->id)->assertNotified(__('gshome.search.started_running'));
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => str_contains($r->url(), '/imports')));
    }

    public function test_again_on_a_link_that_became_a_listing_opens_the_card(): void
    {
        RealEstateFake::fake(imports: self::SITES, importPosts: [Http::response(['data' => RealEstateFake::importListing(['status' => 'done', 'listing_id' => 77])], 202)]);
        $user = $this->seller();
        $this->actingAs($user);
        $search = ListingSearch::query()->create([
            'user_id' => $user->id, 'filters' => ['link' => 'https://www.myhome.ge/pr/21345678/', 'link_source' => 'myhome_ge'],
            'body_hash' => 'old', 'remote_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'status' => 'done', 'found' => 0, 'truncated' => false, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(),
        ]);

        Livewire::test(MySearches::class)->call('searchAgain', $search->id)->assertRedirect(LinkImports::cardUrl($user, 77));
        Queue::assertNothingPushed();
    }

    public function test_again_repeats_a_link_search_with_its_link(): void
    {
        RealEstateFake::fake(imports: self::SITES, importPosts: [Http::response(['data' => RealEstateFake::importSearch(['status' => 'done'])], 202)]);
        $user = $this->seller();
        $this->actingAs($user);
        $search = ListingSearch::query()->create([
            'user_id' => $user->id, 'filters' => ['link' => 'https://home.ss.ge/ka/udzravi-qoneba/l/bina?cityIdList=95', 'link_source' => 'ss_ge'],
            'body_hash' => 'old', 'remote_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'status' => 'done', 'found' => 0, 'truncated' => false, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(),
        ]);

        Livewire::test(MySearches::class)->call('searchAgain', $search->id);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/imports') && $r['url'] === 'https://home.ss.ge/ka/udzravi-qoneba/l/bina?cityIdList=95');
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/search-jobs'));
    }
}
