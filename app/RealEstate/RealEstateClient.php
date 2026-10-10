<?php

namespace App\RealEstate;

use App\RealEstate\Exceptions\RealEstateAccessDenied;
use App\RealEstate\Exceptions\RealEstateBadRequest;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\Exceptions\RealEstateUnavailable;
use Carbon\Carbon;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Тонкий клиент API Real Estate (~/Sites/real-estate.devstudio.ge/docs/RealEstate/16_API.md).
 * Объявления не кешируются (решение «мгновенно»), справочники — сутки.
 */
#[Singleton]
class RealEstateClient
{
    private const DICTIONARY_TTL = 86400;

    /** Сколько секунд после обрыва связи не ходить в API вовсе (общий маркер для всех запросов). */
    private const DOWN_TTL = 30;

    private const DOWN_KEY = 'real-estate:down';

    /** Связь уже оборвалась в этом запросе — остальные вызовы падают сразу, без новых таймаутов. */
    private bool $down = false;

    /** @param  array<string, string>  $params */
    public function listings(array $params, ?string $cursor): ListingPage
    {
        $query = $params + ['lang' => app()->getLocale()] + ($cursor !== null ? ['cursor' => $cursor] : []);
        $json = $this->get('/listings', $query)->json();

        return new ListingPage(
            array_values(array_map(fn (array $d): Listing => Listing::fromApi($d, app()->getLocale()), $json['data'] ?? [])),
            $json['meta']['next_cursor'] ?? null,
            $json['meta']['prev_cursor'] ?? null,
        );
    }

    public function listing(int $id): ?Listing
    {
        $response = $this->get("/listings/{$id}", ['lang' => app()->getLocale()], allowNotFound: true);

        return $response === null ? null : Listing::fromApi($response->json('data'), app()->getLocale());
    }

    /**
     * @param  array<string, scalar>  $params
     * @return Collection<int, array<string, mixed>>
     */
    public function dictionary(string $name, array $params = []): Collection
    {
        $key = 'real-estate:dict:'.$name.':'.md5(serialize($params));

        return collect(Cache::remember($key, self::DICTIONARY_TTL, fn (): array => $this->get("/dictionaries/{$name}", $params)->json('data') ?? []));
    }

    /** @return array<string, int> slug ⇒ id */
    public function dealTypeIds(): array
    {
        return $this->dictionary('deal-types')->mapWithKeys(fn (array $t): array => [(string) $t['slug'] => (int) $t['id']])->all();
    }

    /** Заголовок справочной записи на языке интерфейса, иначе грузинский. */
    public static function title(array $row): string
    {
        return (string) (($row['title_'.app()->getLocale()] ?? null) ?: ($row['title_ka'] ?? $row['slug'] ?? $row['id']));
    }

    public function phoneFiltersEnabled(): bool
    {
        return (bool) config('services.real_estate.phone_filters');
    }

    /** @param  array<string, scalar>  $query */
    private function get(string $path, array $query, bool $allowNotFound = false): ?Response
    {
        if ($this->down || Cache::has(self::DOWN_KEY)) {
            throw new RealEstateUnavailable('Real Estate: нет связи (недавний сбой)');
        }

        try {
            $response = $this->http()->get($path, $query);
        } catch (ConnectionException) {
            $this->markDown();

            throw new RealEstateUnavailable('Real Estate: нет связи');
        } catch (RequestException $e) {
            $response = $e->response;
        }

        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        return match (true) {
            $response->successful() => $response,
            in_array($response->status(), [401, 403], true) => $this->denied($response),
            $response->status() === 429 => throw new RealEstateRateLimited(self::retryAfter($response->header('Retry-After'))),
            $response->status() === 422 => $this->badRequest($path, $response),
            default => $this->unavailable($response),
        };
    }

    private function markDown(): void
    {
        $this->down = true;
        Cache::put(self::DOWN_KEY, true, self::DOWN_TTL);
    }

    /** 503 (query_timeout) — беда одного тяжёлого запроса, не всего API: остальные вызовы не блокируем. */
    private function unavailable(Response $response): never
    {
        if ($response->status() !== 503) {
            $this->markDown();
        }

        throw new RealEstateUnavailable("Real Estate: HTTP {$response->status()}");
    }

    /** Retry-After — секунды или HTTP-дата; всегда ≥ 1, при мусоре 60. */
    private static function retryAfter(string $header): int
    {
        $header = trim($header);
        if (ctype_digit($header)) {
            return max(1, (int) $header);
        }
        try {
            return $header === '' ? 60 : max(1, (int) ceil(now()->diffInSeconds(Carbon::parse($header), false)));
        } catch (\Throwable) {
            return 60;
        }
    }

    private function denied(Response $response): never
    {
        $code = (string) $response->json('error.code');
        Log::error('Real Estate API: доступ закрыт', ['code' => $code, 'status' => $response->status()]);

        throw new RealEstateAccessDenied("Real Estate: {$code}");
    }

    private function badRequest(string $path, Response $response): never
    {
        Log::error('Real Estate API: 422 — ошибка в запросе GS Home', ['path' => $path, 'fields' => $response->json('error.fields')]);

        throw new RealEstateBadRequest('Real Estate: validation_failed');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('services.real_estate.url'))
            ->withToken((string) config('services.real_estate.key'))
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            // 503 = query_timeout: API просит не повторять тот же запрос. Две попытки по 10 с — предел ~20 с на вызов,
            // а после первого обрыва остальные вызовы запроса не ходят в сеть (см. $down).
            ->retry(2, 200, fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [500, 502, 504], true)), throw: false);
    }
}
