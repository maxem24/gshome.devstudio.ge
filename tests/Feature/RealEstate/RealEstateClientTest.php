<?php

namespace Tests\Feature\RealEstate;

use App\RealEstate\Exceptions\RealEstateAccessDenied;
use App\RealEstate\Exceptions\RealEstateBadRequest;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\Exceptions\RealEstateUnavailable;
use App\RealEstate\RealEstateClient;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class RealEstateClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
    }

    private function client(): RealEstateClient
    {
        return app(RealEstateClient::class);
    }

    public function test_listings_page_with_cursor_and_key_and_language(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing()], next: 'N1', prev: 'P1')]);

        $page = $this->client()->listings(['deal_type_id' => '1'], 'CUR');

        $this->assertSame(13, $page->listings[0]->id);
        $this->assertSame('N1', $page->nextCursor);
        $this->assertSame('P1', $page->prevCursor);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings?')
            && $r['cursor'] === 'CUR' && $r['deal_type_id'] === '1' && $r['lang'] === app()->getLocale()
            && $r->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function test_listing_404_is_null(): void
    {
        RealEstateFake::fake();

        $this->assertNull($this->client()->listing(999));
    }

    public function test_listing_found(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);

        $this->assertSame(13, $this->client()->listing(13)?->id);
    }

    /** @return array<int, array{int, array<string, mixed>, array<string, string>, class-string<\Throwable>}> */
    public static function errors(): array
    {
        return [
            '401' => [401, ['error' => ['code' => 'token_revoked']], [], RealEstateAccessDenied::class],
            '403' => [403, ['error' => ['code' => 'client_disabled']], [], RealEstateAccessDenied::class],
            '429' => [429, ['error' => ['code' => 'rate_limited']], ['Retry-After' => '17'], RealEstateRateLimited::class],
            '422' => [422, ['error' => ['code' => 'validation_failed', 'fields' => ['x' => ['bad']]]], [], RealEstateBadRequest::class],
            '503' => [503, ['error' => ['code' => 'server_error']], [], RealEstateUnavailable::class],
        ];
    }

    /** Отдельный случай на каждый код: Http::fake в цикле копит подмены, и побеждает первая. */
    #[DataProvider('errors')]
    public function test_errors_map_to_exceptions(int $status, array $body, array $headers, string $exception): void
    {
        Http::fake(['*' => Http::response($body, $status, $headers)]);

        try {
            $this->client()->listings([], null);
            $this->fail("{$status} не бросил исключение");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
            if ($e instanceof RealEstateRateLimited) {
                $this->assertSame(17, $e->retryAfter);
            }
        }
    }

    public function test_connection_error_is_unavailable(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('down')]);

        $this->expectException(RealEstateUnavailable::class);
        $this->client()->listings([], null);
    }

    public function test_dictionaries_are_cached_listings_are_not(): void
    {
        RealEstateFake::fake([RealEstateFake::page([])]);

        $this->client()->dealTypeIds();
        $this->client()->dealTypeIds();
        $this->client()->listings([], null);
        $this->client()->listings([], null);

        Http::assertSentCount(3); // 1 справочник + 2 списка
        $this->assertSame(['sale' => 1, 'rent' => 2, 'daily_rent' => 3, 'pledge' => 4], $this->client()->dealTypeIds());
    }

    public function test_key_never_appears_in_exception_message(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'token_invalid']], 401)]);

        try {
            $this->client()->listings([], null);
            $this->fail('ожидалось исключение');
        } catch (RealEstateAccessDenied $e) {
            $this->assertStringNotContainsString('test-key', $e->getMessage());
        }
    }

    public function test_503_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'query_timeout']], 503)]);

        try {
            $this->client()->listings([], null);
            $this->fail('ожидалось исключение');
        } catch (RealEstateUnavailable) {
            Http::assertSentCount(1);
        }
    }

    public function test_502_is_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'server_error']], 502)]);

        try {
            $this->client()->listings([], null);
            $this->fail('ожидалось исключение');
        } catch (RealEstateUnavailable) {
            Http::assertSentCount(2); // retry(2, ...) = две попытки всего
        }
    }

    public function test_after_a_connection_failure_next_calls_make_no_http_request(): void
    {
        $attempts = 0;
        Http::fake(['*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('down');
        }]);
        $client = $this->client();

        try {
            $client->listings([], null);
            $this->fail('ожидалось исключение');
        } catch (RealEstateUnavailable) {
        }
        $first = $attempts;
        $this->assertGreaterThan(0, $first);

        $this->expectException(RealEstateUnavailable::class);
        try {
            $client->listings([], null);
        } finally {
            $this->assertSame($first, $attempts);
        }
    }

    public function test_dictionary_failure_is_remembered_across_instances(): void
    {
        Cache::flush();
        $attempts = 0;
        Http::fake(['*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('down');
        }]);

        try {
            $this->client()->dictionary('cities');
        } catch (RealEstateUnavailable) {
        }
        $first = $attempts;

        try {
            (new RealEstateClient)->dictionary('property-types');
            $this->fail('ожидалось исключение');
        } catch (RealEstateUnavailable) {
            $this->assertSame($first, $attempts);
        }
    }

    public function test_retry_after_may_be_an_http_date(): void
    {
        $this->travelTo(Carbon::parse('Sat, 10 Oct 2026 12:00:00 GMT'));
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => 'Sat, 10 Oct 2026 12:01:30 GMT'])]);

        try {
            $this->client()->listings([], null);
            $this->fail('ожидалось исключение');
        } catch (RealEstateRateLimited $e) {
            $this->assertSame(90, $e->retryAfter);
        }
    }

    public function test_retry_after_is_never_zero(): void
    {
        foreach (['0', 'garbage', 'Mon, 01 Jan 2001 00:00:00 GMT'] as $value) {
            Http::fake(['*' => Http::response([], 429, ['Retry-After' => $value])]);
            $this->app->forgetInstance(RealEstateClient::class);

            try {
                $this->client()->listings([], null);
                $this->fail('ожидалось исключение');
            } catch (RealEstateRateLimited $e) {
                $this->assertGreaterThanOrEqual(1, $e->retryAfter, $value);
            }
        }
    }
}
