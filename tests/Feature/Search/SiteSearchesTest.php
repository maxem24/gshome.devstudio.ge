<?php

namespace Tests\Feature\Search;

use App\Enums\Direction;
use App\Jobs\PollListingSearch;
use App\Models\Company;
use App\Models\ListingSearch;
use App\Models\User;
use App\Search\SiteSearches;
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

        $search = app(SiteSearches::class)->start($this->nino, $this->form);

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

        $first = app(SiteSearches::class)->start($this->nino, $this->form);
        $second = app(SiteSearches::class)->start($this->nino, $this->form);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->posts());
    }

    public function test_fresh_done_search_is_reused_but_old_one_is_not(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [
            Http::response($this->job(['status' => 'done', 'found' => 2, 'listings' => [['id' => 7], ['id' => 9]]]), 202),
        ]);

        $first = app(SiteSearches::class)->start($this->nino, $this->form);
        $this->assertSame('done', $first->status);
        $this->assertSame([7, 9], $first->listing_ids);
        Queue::assertNothingPushed();

        $this->assertSame($first->id, app(SiteSearches::class)->start($this->nino, $this->form)->id);
        $this->assertSame(1, $this->posts());

        $this->travel(31)->minutes();
        $again = app(SiteSearches::class)->start($this->nino, $this->form);
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
        $search = $searches->start($this->nino, $this->form);

        (new PollListingSearch($search->id))->handle($searches);
        $searches->finish($search->fresh());
        $searches->finish($search);

        $search->refresh();
        $this->assertSame('done', $search->status);
        $this->assertNotNull($search->notified_at);
        $this->assertSame(1, $this->nino->notifications()->count());
        $this->assertSame('Поиск готов: 3 объявлений', $this->nino->notifications()->first()->data['title']);
        $this->assertSame(1, Activity::query()->where('event', 'search.finished')->count());
    }

    public function test_done_with_unfinished_site_is_partial(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(['status' => 'done', 'found' => 5, 'sites' => [
            'myhome_ge' => ['status' => 'done', 'found' => 5],
            'ss_ge' => ['status' => 'failed', 'found' => 0],
        ]]), 202)]);

        app(SiteSearches::class)->start($this->nino, $this->form);

        $this->assertSame('Поиск готов частично: 5 объявлений', $this->nino->notifications()->first()->data['title']);
    }

    public function test_timeout_marks_timed_out_and_notifies(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $searches = app(SiteSearches::class);
        $search = $searches->start($this->nino, $this->form);

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
        $search = $searches->start($this->nino, $this->form);
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
        $search = $searches->start($this->nino, $this->form);

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
        $search = $searches->start($this->nino, $this->form);

        (new PollListingSearch($search->id))->handle($searches);

        $this->assertSame('failed', $search->fresh()->status);
        $this->assertSame(1, $this->nino->notifications()->count());
        Queue::assertPushed(PollListingSearch::class, 1);
    }

    public function test_visible_to_follows_org_access(): void
    {
        Queue::fake();
        RealEstateFake::fake(searchPosts: [Http::response($this->job(), 202)]);
        $mine = app(SiteSearches::class)->start($this->nino, $this->form);
        $other = User::factory()->agent($this->nino->teamLead)->create();

        $this->assertSame([$mine->id], ListingSearch::query()->visibleTo($this->nino)->pluck('id')->all());
        $this->assertSame([], ListingSearch::query()->visibleTo($other)->pluck('id')->all());
        $this->assertSame([$mine->id], ListingSearch::query()->visibleTo($this->nino->teamLead)->pluck('id')->all());
    }
}
