<?php

namespace Tests\Feature\Search;

use App\Enums\Direction;
use App\Jobs\PollListingSearch;
use App\Models\Company;
use App\Models\ListingSearch;
use App\Models\User;
use App\Search\SearchStart;
use App\Search\SiteSearches;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class SiteSearchesTest extends TestCase
{
    use RefreshDatabase;

    private User $nino;

    /** @var array<string, mixed> */
    private array $form = ['deal' => 'sale', 'city_id' => 1, 'property_type_id' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        $lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
        $this->nino = User::factory()->agent($lead)->create();
    }

    private function job(array $overrides = []): array
    {
        return ['data' => RealEstateFake::searchJob($overrides)];
    }

    private function posts(): int
    {
        return Http::recorded(fn (Request $r): bool => $r->method() === 'POST')->count();
    }

    public function test_start_stores_search_and_queues_polling(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);

        $search = app(SiteSearches::class)->start($this->nino, $this->form)->search;

        $this->assertSame('queued', $search->status);
        $this->assertSame('0b0e7d3a-5c1f-4a53-9a55-3a1f0c9d2e11', $search->remote_id);
        $this->assertSame($this->form, $search->filters);
        $this->assertDatabaseHas('activity_log', ['event' => 'search.started', 'subject_id' => $search->id]);
        Queue::assertPushed(PollListingSearch::class, fn (PollListingSearch $j): bool => $j->searchId === $search->id && $j->delay === 10);
    }

    public function test_same_filters_while_running_return_same_search_without_second_post(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);

        $first = app(SiteSearches::class)->start($this->nino, $this->form)->search;
        $second = app(SiteSearches::class)->start($this->nino, $this->form)->search;

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->posts());
    }

    public function test_fresh_done_search_is_reused_but_old_one_is_not(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [
            Http::response($this->job(['status' => 'done', 'found' => 2, 'listings' => [['id' => 7], ['id' => 9]]]), 202),
        ]);

        $first = app(SiteSearches::class)->start($this->nino, $this->form)->search;
        $this->assertSame('done', $first->status);
        $this->assertSame([7, 9], $first->listing_ids);
        Queue::assertNothingPushed();

        $this->assertSame($first->id, app(SiteSearches::class)->start($this->nino, $this->form)->search->id);
        $this->assertSame(1, $this->posts());

        $this->travel(31)->minutes();
        $again = app(SiteSearches::class)->start($this->nino, $this->form)->search;
        $this->assertNotSame($first->id, $again->id);
        $this->assertSame(2, $this->posts());
    }

    public function test_polling_done_notifies_once_even_if_finish_called_twice(): void
    {
        Queue::fake();
        RealEstateFake::fake(
            searchPosts: [Http::response($this->job(), 202)],
            searchGets: [Http::response($this->job(['status' => 'done', 'found' => 3, 'listings' => [['id' => 1]], 'sites' => [
                'myhome_ge' => ['status' => 'done', 'found' => 3],
                'ss_ge' => ['status' => 'done', 'found' => 0],
            ]]))],
        );
        $searches = app(SiteSearches::class);
        $search = $searches->start($this->nino, $this->form)->search;

        (new PollListingSearch($search->id))->handle($searches);
        $searches->finish($search->fresh());
        $searches->finish($search);

        $search->refresh();
        $this->assertSame('done', $search->status);
        $this->assertNotNull($search->notified_at);
        $this->assertSame(1, $this->nino->notifications()->count());
        $notification = $this->nino->notifications()->first()->data;
        $this->assertSame('Поиск готов: 1 объявлений', $notification['title']);
        $this->assertSame('Найдено на сайтах: 3', $notification['body']);
        $this->assertSame(1, Activity::query()->where('event', 'search.finished')->count());
    }

    public function test_done_with_unfinished_site_is_partial(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(['status' => 'done', 'found' => 5, 'listings' => [['id' => 1], ['id' => 2]], 'sites' => [
            'myhome_ge' => ['status' => 'done', 'found' => 5],
            'ss_ge' => ['status' => 'failed', 'found' => 0],
        ]]), 202)]);

        app(SiteSearches::class)->start($this->nino, $this->form);

        $this->assertSame('Поиск готов частично: 2 объявлений', $this->nino->notifications()->first()->data['title']);
    }

    public function test_timeout_marks_timed_out_and_notifies(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $searches = app(SiteSearches::class);
        $search = $searches->start($this->nino, $this->form)->search;

        $this->travel(31)->minutes();
        (new PollListingSearch($search->id))->handle($searches);

        $this->assertSame('timed_out', $search->fresh()->status);
        $this->assertSame(1, $this->nino->notifications()->count());
        $this->assertSame('Не дождались сборщиков', $this->nino->notifications()->first()->data['title']);
        Queue::assertPushed(PollListingSearch::class, 1);
    }

    public function test_finished_search_is_not_polled(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(['status' => 'done']), 202)]);
        $searches = app(SiteSearches::class);
        $search = $searches->start($this->nino, $this->form)->search;
        Http::fake();

        (new PollListingSearch($search->id))->handle($searches);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_network_error_reschedules_without_exception(): void
    {
        Queue::fake();
        RealEstateFake::fake(
            searchPosts: [Http::response($this->job(), 202)],
            searchGets: [Http::failedConnection()],
        );
        $searches = app(SiteSearches::class);
        $search = $searches->start($this->nino, $this->form)->search;

        (new PollListingSearch($search->id))->handle($searches);

        $this->assertSame('queued', $search->fresh()->status);
        Queue::assertPushed(PollListingSearch::class, 2);
    }

    public function test_remote_404_fails_search_and_notifies(): void
    {
        Queue::fake();
        RealEstateFake::fake(
            searchPosts: [Http::response($this->job(), 202)],
            searchGets: [Http::response(['error' => ['code' => 'not_found']], 404)],
        );
        $searches = app(SiteSearches::class);
        $search = $searches->start($this->nino, $this->form)->search;

        (new PollListingSearch($search->id))->handle($searches);

        $this->assertSame('failed', $search->fresh()->status);
        $this->assertSame(1, $this->nino->notifications()->count());
        Queue::assertPushed(PollListingSearch::class, 1);
    }

    public function test_visible_to_follows_org_access(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $mine = app(SiteSearches::class)->start($this->nino, $this->form)->search;
        $other = User::factory()->agent($this->nino->teamLead)->create();

        $this->assertSame([$mine->id], ListingSearch::query()->visibleTo($this->nino)->pluck('id')->all());
        $this->assertSame([], ListingSearch::query()->visibleTo($other)->pluck('id')->all());
        $this->assertSame([$mine->id], ListingSearch::query()->visibleTo($this->nino->teamLead)->pluck('id')->all());
    }

    public function test_skipped_site_does_not_make_search_partial(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(['status' => 'done', 'found' => 4, 'listings' => [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], 'sites' => [
            'myhome_ge' => ['status' => 'done', 'found' => 4],
            'ss_ge' => ['status' => 'skipped', 'found' => 0],
        ]]), 202)]);

        app(SiteSearches::class)->start($this->nino, $this->form);

        $this->assertSame('Поиск готов: 4 объявлений', $this->nino->notifications()->first()->data['title']);
    }

    public function test_job_failure_closes_running_search_as_failed_and_notifies(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $search = app(SiteSearches::class)->start($this->nino, $this->form)->search;

        (new PollListingSearch($search->id))->failed(new \RuntimeException('boom'));

        $this->assertSame('failed', $search->fresh()->status);
        $this->assertSame(1, $this->nino->notifications()->count());
    }

    public function test_dead_running_search_is_timed_out_and_new_one_started(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $old = app(SiteSearches::class)->start($this->nino, $this->form)->search;

        $this->travel(31)->minutes();
        $new = app(SiteSearches::class)->start($this->nino, $this->form)->search;

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('timed_out', $old->fresh()->status);
        $this->assertSame(2, $this->posts());
    }

    public function test_late_close_does_not_overwrite_done(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $searches = app(SiteSearches::class);
        $stale = $searches->start($this->nino, $this->form)->search;
        $copy = ListingSearch::query()->find($stale->id);
        $searches->close($copy, 'done');

        $searches->close($stale, 'timed_out');

        $this->assertSame('done', $stale->fresh()->status);
        $this->assertSame(1, $this->nino->notifications()->count());
    }

    public function test_non_404_bad_request_keeps_polling(): void
    {
        Queue::fake();
        RealEstateFake::fake(
            searchPosts: [Http::response($this->job(), 202)],
            searchGets: [Http::response(['error' => ['code' => 'validation']], 422)],
        );
        $searches = app(SiteSearches::class);
        $search = $searches->start($this->nino, $this->form)->search;

        (new PollListingSearch($search->id))->handle($searches);

        $this->assertSame('queued', $search->fresh()->status);
        Queue::assertPushed(PollListingSearch::class, 2);
    }

    public function test_timeout_notification_has_no_duplicate_body(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $searches = app(SiteSearches::class);
        $search = $searches->start($this->nino, $this->form)->search;
        $this->travel(31)->minutes();
        (new PollListingSearch($search->id))->handle($searches);

        $this->assertNull($this->nino->notifications()->first()->data['body'] ?? null);
    }

    public function test_truncated_bell_shows_shown_count_and_found_on_sites(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(['status' => 'done', 'found' => 250, 'truncated' => true, 'sites' => ['myhome_ge' => ['status' => 'done', 'found' => 250]],
            'listings' => array_map(fn (int $i): array => ['id' => $i], range(1, 100))]), 202)]);

        app(SiteSearches::class)->start($this->nino, $this->form);

        $data = $this->nino->notifications()->first()->data;
        $this->assertSame('Поиск готов: 100 объявлений', $data['title']);
        $this->assertSame('Найдено на сайтах: 250, показаны 100 самых свежих', $data['body']);
    }

    public function test_start_reports_new_running_and_reused(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $searches = app(SiteSearches::class);

        $first = $searches->start($this->nino, $this->form);
        $second = $searches->start($this->nino, $this->form);
        $this->assertSame(SearchStart::NEW, $first->kind);
        $this->assertSame(SearchStart::RUNNING, $second->kind);
        $this->assertSame($first->search->id, $second->search->id);

        $searches->close($first->search, 'done');
        $third = $searches->start($this->nino, $this->form);
        $this->assertSame(SearchStart::REUSED, $third->kind);
        $this->assertSame($first->search->id, $third->search->id);
    }

    public function test_dispatch_failure_closes_search_as_failed_and_notifies(): void
    {
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new \RuntimeException('redis down'));

        $start = app(SiteSearches::class)->start($this->nino, $this->form);

        $this->assertSame('failed', $start->search->fresh()->status);
        $this->assertSame(1, $this->nino->notifications()->count());
    }

    public function test_sweep_closes_only_stale_running_searches_once(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $searches = app(SiteSearches::class);
        $old = $searches->start($this->nino, $this->form)->search;
        $this->travel(31)->minutes();
        $young = $searches->start($this->nino, ['deal' => 'sale', 'city_id' => 2])->search;

        $this->artisan('search:sweep')->assertSuccessful();
        $this->artisan('search:sweep')->assertSuccessful();

        $this->assertSame('timed_out', $old->fresh()->status);
        $this->assertSame('queued', $young->fresh()->status);
        $this->assertSame(1, $this->nino->notifications()->count());
    }

    public function test_access_denied_while_polling_fails_search_immediately(): void
    {
        Queue::fake();
        RealEstateFake::fake(
            searchPosts: [Http::response($this->job(), 202)],
            searchGets: [Http::response(['error' => ['code' => 'key_revoked']], 403)],
        );
        $searches = app(SiteSearches::class);
        $search = $searches->start($this->nino, $this->form)->search;

        (new PollListingSearch($search->id))->handle($searches);

        $search->refresh();
        $this->assertSame('failed', $search->status);
        $this->assertSame(__('gshome.errors.access_denied'), $search->message);
        $this->assertSame(1, $this->nino->notifications()->count());
        Queue::assertPushed(PollListingSearch::class, 1);
    }

    public function test_polling_and_failure_use_owner_locale(): void
    {
        Queue::fake();
        $this->nino->update(['locale' => 'en']);
        RealEstateFake::fake(
            searchPosts: [Http::response($this->job(), 202)],
            searchGets: [Http::response($this->job(['status' => 'searching']))],
        );
        $searches = app(SiteSearches::class);
        $search = $searches->start($this->nino->fresh(), $this->form)->search;

        (new PollListingSearch($search->id))->handle($searches);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && str_contains($r->url(), 'lang=en'));

        (new PollListingSearch($search->id))->failed(new \RuntimeException('boom'));
        $this->assertSame('Search failed', $search->fresh()->message);
        $this->assertSame('Search failed', $this->nino->notifications()->first()->data['title']);
    }
}
