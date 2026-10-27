<?php

namespace Tests\Feature\RealEstate;

use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\Exceptions\RealEstateRefreshBusy;
use App\RealEstate\Exceptions\RealEstateRefreshNotFound;
use App\RealEstate\Exceptions\RealEstateUnavailable;
use App\RealEstate\RealEstateClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingRefreshClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
    }

    public function test_start_posts_once_with_lang_and_parses(): void
    {
        RealEstateFake::fake(refreshPosts: [Http::response(['data' => RealEstateFake::refresh()], 202)]);

        $r = app(RealEstateClient::class)->startRefresh(13);

        $this->assertSame('queued', $r?->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $q): bool => $q->method() === 'POST' && str_ends_with($q->url(), '/listings/13/refresh?lang='.app()->getLocale()));
    }

    public function test_start_404_is_null(): void
    {
        RealEstateFake::fake(refreshPosts: [Http::response(['error' => ['code' => 'not_found']], 404)]);

        $this->assertNull(app(RealEstateClient::class)->startRefresh(13));
    }

    public function test_start_busy_carries_retry_after(): void
    {
        RealEstateFake::fake(refreshPosts: [Http::response(['error' => ['code' => 'refresh_busy']], 503, ['Retry-After' => '30'])]);

        try {
            app(RealEstateClient::class)->startRefresh(13);
            $this->fail('нужен RealEstateRefreshBusy');
        } catch (RealEstateRefreshBusy $e) {
            $this->assertSame(30, $e->retryAfter);
        }
    }

    public function test_start_429_is_rate_limited(): void
    {
        RealEstateFake::fake(refreshPosts: [Http::response([], 429, ['Retry-After' => '12'])]);

        $this->expectException(RealEstateRateLimited::class);
        app(RealEstateClient::class)->startRefresh(13);
    }

    public function test_start_is_never_retried_on_500(): void
    {
        RealEstateFake::fake(refreshPosts: [Http::response([], 500)]);

        try {
            app(RealEstateClient::class)->startRefresh(13);
            $this->fail('нужен RealEstateUnavailable');
        } catch (RealEstateUnavailable) {
            Http::assertSentCount(1);
        }
    }

    public function test_state_404_is_refresh_not_found(): void
    {
        RealEstateFake::fake(refreshGets: [Http::response(['error' => ['code' => 'not_found']], 404)]);

        $this->expectException(RealEstateRefreshNotFound::class);
        app(RealEstateClient::class)->refreshState('abc');
    }

    public function test_forget_card_clears_every_locale(): void
    {
        foreach (['ru', 'en', 'ka'] as $l) {
            Cache::put("real-estate:card:{$l}:13", ['id' => 13], 60);
        }

        app(RealEstateClient::class)->forgetCard(13);

        foreach (['ru', 'en', 'ka'] as $l) {
            $this->assertFalse(Cache::has("real-estate:card:{$l}:13"));
        }
    }
}
