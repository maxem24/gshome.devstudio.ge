<?php

namespace Tests\Feature\RealEstate;

use App\RealEstate\Exceptions\RealEstateAccessDenied;
use App\RealEstate\Exceptions\RealEstateBadRequest;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\Exceptions\RealEstateStaleCursor;
use App\RealEstate\Exceptions\RealEstateUnavailable;
use App\RealEstate\RealEstateClient;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /** @return array<int|string, array{int, array<string, mixed>, array<string, string>, class-string<\Throwable>}> */
    public static function errors(): array
    {
        return [
            '401' => [401, ['error' => ['code' => 'token_revoked']], [], RealEstateAccessDenied::class],
            '403' => [403, ['error' => ['code' => 'client_disabled']], [], RealEstateAccessDenied::class],
            '429' => [429, ['error' => ['code' => 'rate_limited']], ['Retry-After' => '17'], RealEstateRateLimited::class],
            '422' => [422, ['error' => ['code' => 'validation_failed', 'fields' => ['x' => ['bad']]]], [], RealEstateBadRequest::class],
            '422 cursor' => [422, ['error' => ['code' => 'validation_failed', 'fields' => ['cursor' => ['bad']]]], [], RealEstateStaleCursor::class],
            '422 cursor and more' => [422, ['error' => ['code' => 'validation_failed', 'fields' => ['cursor' => ['bad'], 'q' => ['bad']]]], [], RealEstateBadRequest::class],
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

    public function test_dictionaries_for_a_day_listings_and_cards_briefly(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing()])], [13 => RealEstateFake::listing()]);

        $this->client()->dealTypeIds();
        $this->client()->dealTypeIds();
        $this->client()->listings([], null);
        $this->client()->listings([], null);
        $this->client()->listing(13);
        $this->client()->listing(13);
        $this->assertSame(13, $this->client()->listingsByIds([13])[13]?->id);
        Http::assertSentCount(3); // справочник, страница, карточка — по разу

        $this->travel(31)->seconds();
        $this->client()->listings([], null);
        $this->client()->listing(13);
        Http::assertSentCount(4); // страница устарела через 30 с, карточка живёт 60 с

        $this->travel(30)->seconds();
        $this->client()->listing(13);
        Http::assertSentCount(5);
        $this->assertSame(['sale' => 1, 'rent' => 2, 'daily_rent' => 3, 'pledge' => 4], $this->client()->dealTypeIds());
    }

    public function test_missing_card_and_broken_page_are_not_cached(): void
    {
        RealEstateFake::fake([['oops' => true]]);

        $this->assertNull($this->client()->listing(99));
        $this->assertNull($this->client()->listing(99));
        Http::assertSentCount(2);

        foreach ([1, 2] as $ignored) {
            try {
                $this->client()->listings([], null);
            } catch (RealEstateUnavailable) {
            }
        }
        Http::assertSentCount(4);
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

    /** @return array<array-key, array{int}> */
    public static function requestFaults(): array
    {
        return ['500' => [500], '503' => [503], '400' => [400], '404' => [404], '414' => [414]];
    }

    /** 500 и 4xx — беда одного запроса: следующий вызов (в т.ч. из нового экземпляра клиента) идёт в сеть. */
    #[DataProvider('requestFaults')]
    public function test_request_fault_does_not_block_other_calls(int $status): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['error' => ['code' => 'x']], $status)]);

        try {
            $this->client()->listings([], null);
            $this->fail('ожидалось исключение');
        } catch (RealEstateUnavailable|RealEstateBadRequest) {
        }
        $this->assertFalse(Cache::has('real-estate:down'));
        $sent = count(Http::recorded());

        try {
            (new RealEstateClient)->listings([], null);
        } catch (RealEstateUnavailable|RealEstateBadRequest) {
        }
        $this->assertGreaterThan($sent, count(Http::recorded()));
    }

    public function test_500_is_retried_once_then_unavailable(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        try {
            $this->client()->listings([], null);
            $this->fail('ожидалось исключение');
        } catch (RealEstateUnavailable) {
            Http::assertSentCount(2);
        }
    }

    public function test_unexpected_4xx_is_bad_request(): void
    {
        Http::fake(['*' => Http::response([], 400)]);

        $this->expectException(RealEstateBadRequest::class);
        $this->client()->listings([], null);
    }

    #[DataProvider('gatewayFaults')]
    public function test_gateway_fault_blocks_other_calls_for_everyone(int $status): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response([], $status)]);

        try {
            $this->client()->listings([], null);
            $this->fail('ожидалось исключение');
        } catch (RealEstateUnavailable) {
        }
        $this->assertTrue(Cache::has('real-estate:down'));
        $sent = count(Http::recorded());

        try {
            (new RealEstateClient)->listings([], null);
            $this->fail('ожидалось исключение');
        } catch (RealEstateUnavailable) {
            $this->assertSame($sent, count(Http::recorded()));
        }
    }

    /** @return array<array-key, array{int}> */
    public static function gatewayFaults(): array
    {
        return ['502' => [502], '504' => [504]];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function brokenBodies(): array
    {
        return [
            'data null' => [['data' => null]],
            'data string' => [['data' => 'x']],
            'empty' => [[]],
            'item without id' => [['data' => [['source' => 'ss_ge']]]],
            'item not an array' => [['data' => ['x']]],
        ];
    }

    /** Отдельный случай на каждое тело: Http::fake копит подмены, и побеждает первая. */
    #[DataProvider('brokenBodies')]
    public function test_broken_list_body_is_unavailable_not_a_type_error(array $body): void
    {
        Http::fake(['*' => Http::response($body)]);

        $this->expectException(RealEstateUnavailable::class);
        $this->client()->listings([], null);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function brokenCards(): array
    {
        return [
            'data null' => [['data' => null]],
            'only id' => [['data' => ['id' => 5]]],
            'bad date' => [['data' => ['id' => 5, 'source' => 'ss_ge', 'source_created_at' => 'not a date']]],
            'images not arrays' => [['data' => ['id' => 5, 'source' => 'ss_ge', 'images' => ['x']]]],
        ];
    }

    #[DataProvider('brokenCards')]
    public function test_broken_card_body_is_unavailable_not_a_type_error(array $body): void
    {
        Http::fake(['*' => Http::response($body)]);

        $this->expectException(RealEstateUnavailable::class);
        $this->client()->listing(5);
    }

    public function test_garbage_phones_in_cluster_are_dropped(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing(['cluster' => [
            'listings_count' => 2, 'phones_e164' => [null, 5, '+995555000111'], 'listings' => [],
        ]])]);

        $this->assertSame(['+995555000111'], $this->client()->listing(13)?->clusterPhonesE164);
    }

    public function test_empty_dictionary_is_not_cached(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['data' => []])]);

        foreach ([1, 2] as $_) {
            try {
                $this->client()->dictionary('cities');
                $this->fail('ожидалось исключение');
            } catch (RealEstateUnavailable) {
            }
        }
        Http::assertSentCount(2);

    }

    /** @return array<string, array{array<mixed>}> */
    public static function brokenDictionaries(): array
    {
        return [
            'no data' => [[]],
            'data not an array' => [['data' => 'x']],
            'rows not arrays' => [['data' => ['x']]],
            'row without id' => [['data' => [['slug' => 'x']]]],
            'one row not an array' => [['data' => [['id' => 1], 'x']]],
        ];
    }

    /** Отдельный тест на тело: подмены Http::fake копятся, и побеждает первая. */
    #[DataProvider('brokenDictionaries')]
    public function test_broken_dictionary_body_is_unavailable_and_not_cached(array $body): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response($body)]);

        foreach ([1, 2] as $_) {
            try {
                $this->client()->dictionary('cities');
                $this->fail('ожидалось исключение');
            } catch (RealEstateUnavailable) {
            }
        }
        Http::assertSentCount(2);
    }

    public function test_deal_types_without_slug_are_unavailable_and_not_cached(): void
    {
        Cache::flush();
        Http::fake(['*' => Http::response(['data' => [['id' => 1]]])]);

        foreach ([1, 2] as $_) {
            try {
                $this->client()->dealTypeIds();
                $this->fail('ожидалось исключение');
            } catch (RealEstateUnavailable) {
            }
        }
        Http::assertSentCount(2);
    }

    public function test_stale_cursor_is_not_logged_as_an_error(): void
    {
        Log::swap($log = \Mockery::spy(LogManager::class));
        Http::fake(['*' => Http::response(['error' => ['code' => 'validation_failed', 'fields' => ['cursor' => ['bad']]]], 422)]);

        try {
            $this->client()->listings([], 'OLD');
            $this->fail('ожидалось исключение');
        } catch (RealEstateStaleCursor) {
        }
        $log->shouldNotHaveReceived('error');
    }

    public function test_parse_failure_is_logged_with_the_exception(): void
    {
        Log::swap($log = \Mockery::spy(LogManager::class));
        Http::fake(['*' => Http::response(['data' => 'x'])]);

        try {
            $this->client()->listings([], null);
            $this->fail('ожидалось исключение');
        } catch (RealEstateUnavailable) {
        }
        $log->shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx): bool => ($ctx['exception'] ?? null) instanceof \Throwable)->once();
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
