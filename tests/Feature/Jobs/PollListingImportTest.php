<?php

namespace Tests\Feature\Jobs;

use App\Jobs\PollListingImport;
use App\Models\User;
use App\Notifications\ListingImported;
use App\RealEstate\RealEstateClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class PollListingImportTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '5d1e2f3a-4b5c-4d6e-8f70-112233445566';

    private const URL = 'https://www.myhome.ge/pr/21345678/';

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        Queue::fake();
        Notification::fake();
    }

    private function poll(User $user, int $startedAt = 0): void
    {
        (new PollListingImport(self::ID, $user->id, self::URL, $startedAt ?: now()->timestamp))->handle(app(RealEstateClient::class));
    }

    public function test_done_notifies_with_a_link_to_the_card(): void
    {
        RealEstateFake::fake(cards: [77 => RealEstateFake::listing(['id' => 77])], importGets: [Http::response(['data' => RealEstateFake::importListing(['status' => 'done', 'listing_id' => 77])])]);
        $user = User::factory()->owner()->create();

        $this->poll($user);

        Notification::assertSentTo($user, ListingImported::class, fn (ListingImported $n): bool => $n->listingId === 77 && $n->title === 'Квартира в Ваке');
        Queue::assertNothingPushed();
    }

    public function test_failed_notifies_with_the_server_reason(): void
    {
        RealEstateFake::fake(importGets: [Http::response(['data' => RealEstateFake::importListing(['status' => 'failed', 'message' => 'Объявление снято с публикации'])])]);
        $user = User::factory()->owner()->create();

        $this->poll($user);

        Notification::assertSentTo($user, ListingImported::class, fn (ListingImported $n): bool => $n->listingId === null && $n->reason === 'Объявление снято с публикации');
    }

    public function test_finished_poll_frees_the_link_for_a_new_paste(): void
    {
        RealEstateFake::fake(importGets: [Http::response(['data' => RealEstateFake::importListing(['status' => 'failed'])])]);
        $user = User::factory()->owner()->create();
        $this->assertTrue(PollListingImport::start(self::ID, $user->id, self::URL));

        $this->poll($user);

        $this->assertTrue(PollListingImport::start(self::ID, $user->id, self::URL));
    }

    public function test_still_running_polls_again_and_gives_up_after_the_cap(): void
    {
        RealEstateFake::fake(importGets: [Http::response(['data' => RealEstateFake::importListing(['status' => 'running'])])]);
        $user = User::factory()->owner()->create();

        $this->poll($user);
        Queue::assertPushed(PollListingImport::class, 1);
        Notification::assertNothingSent();

        $this->poll($user, now()->subMinutes(21)->timestamp);
        Queue::assertPushed(PollListingImport::class, 1);
        Notification::assertSentTo($user, ListingImported::class, fn (ListingImported $n): bool => $n->reason === __('gshome.link.timed_out'));
    }

    public function test_network_failure_keeps_polling(): void
    {
        RealEstateFake::fake(importGets: [Http::response([], 500)]);
        $user = User::factory()->owner()->create();

        $this->poll($user);

        Queue::assertPushed(PollListingImport::class, 1);
        Notification::assertNothingSent();
    }

    public function test_second_start_of_the_same_import_is_not_queued(): void
    {
        $this->assertTrue(PollListingImport::start(self::ID, 1, self::URL));
        $this->assertFalse(PollListingImport::start(self::ID, 1, self::URL));
        $this->assertTrue(PollListingImport::start(self::ID, 2, self::URL));
        Queue::assertPushed(PollListingImport::class, 2);
    }
}
