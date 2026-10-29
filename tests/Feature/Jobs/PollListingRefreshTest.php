<?php

namespace Tests\Feature\Jobs;

use App\Jobs\PollListingRefresh;
use App\Models\User;
use App\Notifications\ListingInfoCollected;
use App\RealEstate\RealEstateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class PollListingRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const REFRESH_ID = '9f3c2a1e-7b4d-4c55-9a0e-1d2f3a4b5c6d';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        $this->user = User::factory()->teamLead()->create();
    }

    private function run_job(array $state, ?int $startedAt = null): void
    {
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing()],
            refreshGets: [Http::response(['data' => RealEstateFake::refresh($state)])],
        );
        (new PollListingRefresh(self::REFRESH_ID, 13, $this->user->id, $startedAt ?? now()->timestamp))
            ->handle(app(RealEstateClient::class));
    }

    public function test_finished_sends_one_notification_with_card_url(): void
    {
        Queue::fake();
        $this->run_job(['status' => 'done']);

        $n = $this->user->notifications()->sole();
        $this->assertSame(ListingInfoCollected::class, $n->type);
        $this->assertStringContainsString('Полная информация по объявлению', $n->data['title']);
        $this->assertStringContainsString('собрана', $n->data['title']);
        $this->assertStringNotContainsString('не полностью', $n->data['title']);
        $this->assertStringContainsString('/listings/13', $n->data['actions'][0]['url']);
        Queue::assertNothingPushed();
    }

    public function test_stale_has_partial_title(): void
    {
        Queue::fake();
        $this->run_job(['status' => 'stale']);

        $this->assertStringContainsString('не полностью', $this->user->notifications()->sole()->data['title']);
    }

    public function test_running_redispatches_without_notification(): void
    {
        Queue::fake();
        $this->run_job(['status' => 'running']);

        $this->assertSame(0, $this->user->notifications()->count());
        Queue::assertPushed(PollListingRefresh::class, fn (PollListingRefresh $j): bool => $j->refreshId === self::REFRESH_ID);
    }

    public function test_cap_stops_polling(): void
    {
        Queue::fake();
        $this->run_job(['status' => 'running'], now()->subMinutes(16)->timestamp);

        Queue::assertNothingPushed();
        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_not_found_is_silent(): void
    {
        Queue::fake();
        RealEstateFake::fake(refreshGets: [Http::response(['error' => ['code' => 'not_found']], 404)]);
        (new PollListingRefresh(self::REFRESH_ID, 13, $this->user->id, now()->timestamp))->handle(app(RealEstateClient::class));

        Queue::assertNothingPushed();
        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_second_start_for_the_same_refresh_is_not_queued(): void
    {
        Queue::fake();

        $this->assertTrue(PollListingRefresh::start(self::REFRESH_ID, 13, $this->user->id));
        $this->assertFalse(PollListingRefresh::start(self::REFRESH_ID, 13, $this->user->id));

        Queue::assertPushed(PollListingRefresh::class, 1);
    }

    public function test_no_notification_when_the_card_already_showed_the_result(): void
    {
        Queue::fake();
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing()],
            refreshGets: [Http::response(['data' => RealEstateFake::refresh(['status' => 'done'])])],
        );
        // После фейка: он очищает кеш, а метка лежит в нём.
        PollListingRefresh::markShown(self::REFRESH_ID);
        (new PollListingRefresh(self::REFRESH_ID, 13, $this->user->id, now()->timestamp))->handle(app(RealEstateClient::class));

        $this->assertSame(0, $this->user->notifications()->count());
    }

    public function test_title_is_read_in_the_users_language_and_locale_is_restored(): void
    {
        Queue::fake();
        $this->user->update(['locale' => 'en']);
        app()->setLocale('ru');
        $this->run_job(['status' => 'done']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/listings/13') && ($request['lang'] ?? null) === 'en');
        $this->assertSame('ru', app()->getLocale());
    }
}
