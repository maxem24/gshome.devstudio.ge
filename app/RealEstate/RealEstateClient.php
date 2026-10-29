<?php

namespace App\RealEstate;

use App\Enums\Locale;
use App\RealEstate\Exceptions\RealEstateAccessDenied;
use App\RealEstate\Exceptions\RealEstateBadRequest;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateFiltersRequired;
use App\RealEstate\Exceptions\RealEstateLinkRejected;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\Exceptions\RealEstateRefreshBusy;
use App\RealEstate\Exceptions\RealEstateRefreshNotFound;
use App\RealEstate\Exceptions\RealEstateSearchNotFound;
use App\RealEstate\Exceptions\RealEstateSearchRejected;
use App\RealEstate\Exceptions\RealEstateStaleCursor;
use App\RealEstate\Exceptions\RealEstateUnavailable;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Тонкий клиент API Real Estate (~/Sites/real-estate.devstudio.ge/docs/RealEstate/16_API.md).
 * Справочники кешируются на сутки, объявления — коротко (решение 2026-10-18): страница списка 30 с,
 * карточка 60 с. В кеше только ответ API; метки владения считаются из нашей базы при каждом показе.
 */
#[Singleton]
class RealEstateClient
{
    private const DICTIONARY_TTL = 86400;

    /** Страница списка: «назад» из карточки и повторный рендер не ходят в API, а свежие объявления видны через полминуты. */
    private const PAGE_TTL = 30;

    /** Карточка: открыть, вернуться, сохранить — один запрос к API, а не три. */
    private const CARD_TTL = 60;

    /** Сколько секунд после обрыва связи или 502/504 не ходить в API вовсе (общий маркер для всех запросов). */
    private const DOWN_TTL = 30;

    private const DOWN_KEY = 'real-estate:down';

    private const IMPORTS_KEY = 'real-estate:me:imports';

    /** Связь уже оборвалась (или шлюз ответил 502/504) в этом запросе — остальные вызовы падают сразу, без новых таймаутов. */
    private bool $down = false;

    /** @param  array<string, string>  $params */
    public function listings(array $params, ?string $cursor): ListingPage
    {
        $first = $params + ['lang' => app()->getLocale()];
        $query = $first + ($cursor !== null ? ['cursor' => $cursor] : []);
        $key = self::pageKey($query);
        $json = Cache::get($key);
        if (! is_array($json)) {
            try {
                $json = $this->get('/listings', $query)->json();
            } catch (RealEstateStaleCursor $e) {
                // Курсор устарел — экран начнёт с первой страницы, и она должна быть свежей, а не той, что выдала этот курсор.
                Cache::forget(self::pageKey($first));

                throw $e;
            }
            // Кешируем только разобранный ответ: HTML прокси или сменившийся формат на 30 с не закрепляем.
            if (is_array($json) && is_array($json['data'] ?? null)) {
                Cache::put($key, $json, self::PAGE_TTL);
            }
        }

        return $this->parsed('/listings', function () use ($json): ListingPage {
            if (! is_array($json) || ! is_array($json['data'] ?? null)) {
                throw new \UnexpectedValueException('data не массив');
            }

            return new ListingPage(
                array_values(array_map(fn (mixed $d): Listing => Listing::fromApi($d, app()->getLocale()), $json['data'])),
                is_string($json['meta']['next_cursor'] ?? null) ? $json['meta']['next_cursor'] : null,
                is_string($json['meta']['prev_cursor'] ?? null) ? $json['meta']['prev_cursor'] : null,
                array_values(array_map('intval', array_filter(is_array($json['meta']['missing_ids'] ?? null) ? $json['meta']['missing_ids'] : [], 'is_numeric'))),
            );
        });
    }

    public function listing(int $id): ?Listing
    {
        $locale = app()->getLocale();
        $data = Cache::get(self::cardKey($id, $locale));
        if (! is_array($data)) {
            $response = $this->get("/listings/{$id}", ['lang' => $locale], allowNotFound: true);
            if ($response === null) {
                return null;
            }
            $data = $response->json('data');
            $this->rememberCard($id, $locale, $data);
        }

        return $this->parsed("/listings/{$id}", fn (): Listing => Listing::fromApi($data, $locale));
    }

    /**
     * Ставит поиск на myhome и ss.ge (POST /search-jobs). Ответ не кешируем: у каждого поиска свой id и своё состояние.
     *
     * @param  array<string, mixed>  $body  тело из SiteSearchRequest::build()
     */
    public function startSearch(array $body): SiteSearchStatus
    {
        // POST не идемпотентен: повтор после принятого сервером задания создал бы дубль и съел лимит 60/ч — без повторов.
        $response = $this->send(fn () => $this->http()->asJson()->retry(1, 0, null, false)->post('/search-jobs', $body));
        if ($response->status() === 422 && $response->json('error.code') === 'filters_required') {
            throw new RealEstateFiltersRequired((string) $response->json('error.message'));
        }
        if ($response->status() === 422 && $response->json('error.code') === 'validation_failed') {
            $fields = $response->json('error.fields');
            // Не наш баг, а отказ сайтов по фильтрам: предупреждение, не ошибка.
            Log::warning('Real Estate отклонил фильтры поиска по сайтам', ['fields' => $fields]);

            throw RealEstateSearchRejected::fromFields('Real Estate: HTTP 422 validation_failed', $fields);
        }

        return $this->searchStatusFrom('/search-jobs', $this->checked('/search-jobs', $response, false));
    }

    /** Состояние поиска; чужой или несуществующий id (404) — RealEstateBadRequest. */
    public function searchStatus(string $id, ?string $lang = null): SiteSearchStatus
    {
        $path = '/search-jobs/'.rawurlencode($id);
        $response = $this->send(fn () => $this->http()->get($path, ['lang' => $lang ?? app()->getLocale()]));

        // 404 — ожидаемый случай (чужой или протухший id): без Log::error из badRequest().
        $checked = $this->checked($path, $response, allowNotFound: true)
            ?? throw new RealEstateSearchNotFound('Real Estate: поиск не найден (404)');

        return $this->searchStatusFrom($path, $checked);
    }

    /**
     * Ссылка на объявление или выдачу myhome / ss.ge (POST /imports). Без повторов — тот же довод, что у startSearch:
     * выдача по ссылке — это поиск и делит с ним лимит.
     */
    public function startImport(string $url): LinkImport
    {
        $path = '/imports';
        $response = $this->send(fn () => $this->http()->asJson()->retry(1, 0, null, false)->post($path.'?lang='.app()->getLocale(), ['url' => $url]));
        $code = $response->json('error.code');
        if ($response->status() === 422 && $code === 'validation_failed') {
            $texts = $response->json('error.fields.url');
            $texts = array_values(array_filter(is_array($texts) ? $texts : [$texts], fn (mixed $t): bool => is_string($t) && $t !== ''));
            Log::warning('Real Estate не принял ссылку', ['url' => $url, 'fields' => $response->json('error.fields')]);

            throw new RealEstateLinkRejected('Real Estate: HTTP 422 /imports', $texts);
        }
        // Новая карточка делит потолок с актуализацией: тот же 503 refresh_busy и тот же текст.
        if ($response->status() === 503 && $code === 'refresh_busy') {
            throw new RealEstateRefreshBusy(self::retryAfter($response->header('Retry-After')));
        }
        // 503 с другим кодом — нет сборщика на связи: это ответ, а не сбой API; без кода — обычный «недоступен».
        if ($response->status() === 503 && is_string($code) && is_string($message = $response->json('error.message')) && $message !== '') {
            throw new RealEstateLinkRejected("Real Estate: HTTP 503 {$code}", [$message]);
        }

        return $this->importFrom($path, $this->checked($path, $response, false));
    }

    /** Состояние ссылки на объявление; чужой или стёртый id (404) — null. */
    public function importState(string $id): ?LinkImport
    {
        $path = '/imports/'.rawurlencode($id);
        $response = $this->send(fn () => $this->http()->get($path, ['lang' => app()->getLocale()]));
        $checked = $this->checked($path, $response, allowNotFound: true);

        return $checked === null ? null : $this->importFrom($path, $checked);
    }

    /**
     * Сайты, ссылки с которых Real Estate сейчас принимает (`GET /me` → `imports`). Пусто — кнопки «По ссылке» нет.
     * Спрашиваем раз в 10 минут: кнопка в шапке на каждой странице. Любой сбой — пусто на минуту.
     *
     * @return list<string>
     */
    public function importSources(): array
    {
        $cached = Cache::get(self::IMPORTS_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $sources = $this->get('/me', [])?->json('data.imports');
            $sources = array_values(array_filter(is_array($sources) ? $sources : [], fn (mixed $s): bool => is_string($s) && $s !== ''));
            Cache::put(self::IMPORTS_KEY, $sources, 600);
        } catch (\Throwable $e) {
            // Кнопка в шапке каждой страницы: любой сбой здесь — «кнопки нет», а не 500 на всей панели.
            Log::warning('Real Estate: GET /me не ответил, кнопка «По ссылке» скрыта', ['error' => $e->getMessage()]);
            $sources = [];
            Cache::put(self::IMPORTS_KEY, $sources, 60);
        }

        return $sources;
    }

    private function importFrom(string $path, Response $response): LinkImport
    {
        $json = $response->json('data');

        return $this->parsed($path, fn (): LinkImport => LinkImport::fromApi($json));
    }

    /**
     * Ставит актуализацию объявления и всех его дублей (POST /listings/{id}/refresh). null — объявления нет (404).
     * Повторное нажатие, пока прошлая не закончилась, API возвращает ту же актуализацию.
     */
    public function startRefresh(int $id): ?ListingRefresh
    {
        $path = "/listings/{$id}/refresh";
        // POST без повторов: тот же довод, что у startSearch, плюс лимит нажатий клиента (10 в минуту).
        $response = $this->send(fn () => $this->http()->asJson()->retry(1, 0, null, false)->post($path.'?lang='.app()->getLocale(), []));
        if ($response->status() === 503 && $response->json('error.code') === 'refresh_busy') {
            throw new RealEstateRefreshBusy(self::retryAfter($response->header('Retry-After')));
        }
        $checked = $this->checked($path, $response, allowNotFound: true);

        return $checked === null ? null : $this->refreshFrom($path, $checked);
    }

    /** Состояние актуализации; чужой или стёртый id (404) — RealEstateRefreshNotFound. */
    public function refreshState(string $id): ListingRefresh
    {
        $path = '/listing-refreshes/'.rawurlencode($id);
        $response = $this->send(fn () => $this->http()->get($path, ['lang' => app()->getLocale()]));
        $checked = $this->checked($path, $response, allowNotFound: true)
            ?? throw new RealEstateRefreshNotFound('Real Estate: актуализация не найдена (404)');

        return $this->refreshFrom($path, $checked);
    }

    /** Забыть кеш карточки на всех языках: после актуализации следующий показ должен взять свежие данные. */
    public function forgetCard(int $id): void
    {
        foreach (Locale::cases() as $locale) {
            Cache::forget(self::cardKey($id, $locale->value));
        }
    }

    private function refreshFrom(string $path, Response $response): ListingRefresh
    {
        $json = $response->json('data');

        return $this->parsed($path, fn (): ListingRefresh => ListingRefresh::fromApi($json));
    }

    private function searchStatusFrom(string $path, Response $response): SiteSearchStatus
    {
        $json = $response->json('data');

        return $this->parsed($path, fn (): SiteSearchStatus => SiteSearchStatus::fromApi($json));
    }

    /** @param  array<string, scalar>  $query */
    private static function pageKey(array $query): string
    {
        ksort($query);

        return 'real-estate:page:'.md5(serialize($query));
    }

    private static function cardKey(int $id, string $locale): string
    {
        return "real-estate:card:{$locale}:{$id}";
    }

    /** 404 не кешируем: снятое объявление и так не показываем, а вернувшееся должно появиться сразу. */
    private function rememberCard(int $id, string $locale, mixed $data): void
    {
        if (is_array($data) && isset($data['id'])) {
            Cache::put(self::cardKey($id, $locale), $data, self::CARD_TTL);
        }
    }

    /**
     * Карточки по списку id параллельно: у API нет выборки по нескольким id, а по одной 50 карточек — секунды.
     * Ошибка одной карточки не роняет остальные: на её месте исключение.
     *
     * @param  list<int>  $ids
     * @return array<int, Listing|RealEstateException|null> id ⇒ карточка, null (нет такой) или причина отказа
     */
    public function listingsByIds(array $ids, int $concurrency = 4): array
    {
        $locale = app()->getLocale();
        $out = [];
        $missing = [];
        foreach ($ids as $id) {
            $data = Cache::get(self::cardKey($id, $locale));
            if (is_array($data)) {
                try {
                    $out[$id] = $this->parsed("/listings/{$id}", fn (): Listing => Listing::fromApi($data, $locale));
                } catch (RealEstateException $e) {
                    $out[$id] = $e;
                }
            } else {
                $out[$id] = null;
                $missing[] = $id;
            }
        }
        if ($missing === []) {
            return $out;
        }
        if ($this->down || Cache::has(self::DOWN_KEY)) {
            return array_replace($out, array_fill_keys($missing, new RealEstateUnavailable('Real Estate: нет связи (недавний сбой)')));
        }

        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (int $id): mixed => $this->configured($pool->as((string) $id))->get("/listings/{$id}", ['lang' => $locale]),
            $missing,
        ), $concurrency);

        foreach ($missing as $id) {
            $response = $responses[(string) $id] ?? null;
            try {
                if (! $response instanceof Response) {
                    $this->markDown();

                    throw new RealEstateUnavailable('Real Estate: нет связи');
                }
                $checked = $this->checked("/listings/{$id}", $response, allowNotFound: true);
                if ($checked === null) {
                    continue;
                }
                $data = $checked->json('data');
                $out[$id] = $this->parsed("/listings/{$id}", fn (): Listing => Listing::fromApi($data, $locale));
                $this->rememberCard($id, $locale, $data);
            } catch (RealEstateException $e) {
                $out[$id] = $e;
            }
        }

        return $out;
    }

    /**
     * Разбор ответа: 200 с HTML от прокси или сменившийся формат — это «API недоступен», а не 500 на странице.
     *
     * @template T
     *
     * @param  \Closure(): T  $parse
     * @return T
     */
    private function parsed(string $path, \Closure $parse): mixed
    {
        try {
            return $parse();
        } catch (\TypeError|\ErrorException|\UnexpectedValueException|InvalidFormatException $e) {
            Log::warning('Real Estate API: неожиданный формат ответа', ['path' => $path, 'exception' => $e]);

            throw new RealEstateUnavailable('Real Estate: неожиданный формат ответа');
        }
    }

    /**
     * @param  array<string, scalar>  $params
     * @return Collection<int, array<string, mixed>>
     */
    public function dictionary(string $name, array $params = []): Collection
    {
        $key = 'real-estate:dict:'.$name.':'.md5(serialize($params));

        return collect(Cache::remember($key, self::DICTIONARY_TTL, function () use ($name, $params): array {
            $data = $this->get("/dictionaries/{$name}", $params)->json('data');
            // Пустой или кривой ответ на сутки не кешируем: иначе одна осечка прокси ломает экран до завтра.
            // Брак: не список, пустой, строки не массивы или без id (для deal-types ещё и без slug) —
            // dealTypeIds() и экран падали бы на них целые сутки.
            if (! is_array($data) || $data === [] || ! array_is_list($data) || array_filter($data, fn (mixed $row): bool => ! is_array($row) || ! isset($row['id']) || ($name === 'deal-types' && ! isset($row['slug']))) !== []) {
                Log::warning('Real Estate API: пустой справочник', ['name' => $name]);

                throw new RealEstateUnavailable("Real Estate: пустой справочник {$name}");
            }

            return $data;
        }));
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
        return $this->checked($path, $this->send(fn () => $this->http()->get($path, $query)), $allowNotFound);
    }

    /** @param  \Closure(): Response  $call */
    private function send(\Closure $call): Response
    {
        if ($this->down || Cache::has(self::DOWN_KEY)) {
            throw new RealEstateUnavailable('Real Estate: нет связи (недавний сбой)');
        }

        try {
            return $call();
        } catch (ConnectionException) {
            $this->markDown();

            throw new RealEstateUnavailable('Real Estate: нет связи');
        }
    }

    /** Ответ API → сам ответ, null (404, если разрешён) или исключение с понятной человеку причиной. */
    private function checked(string $path, Response $response, bool $allowNotFound): ?Response
    {
        if ($allowNotFound && $response->status() === 404) {
            return null;
        }

        return match (true) {
            $response->successful() => $response,
            in_array($response->status(), [401, 403], true) => $this->denied($response),
            $response->status() === 429 => throw new RealEstateRateLimited(self::retryAfter($response->header('Retry-After'))),
            in_array($response->status(), [502, 504], true) => $this->gatewayDown($response),
            $response->status() >= 500 => throw new RealEstateUnavailable("Real Estate: HTTP {$response->status()}"),
            default => $this->badRequest($path, $response),
        };
    }

    /**
     * Пометка «API недоступен» (общий кеш, 30 с) — только на обрыв связи и на 502/504 после повторов:
     * это беда шлюза, а не запроса. 500, 503 и 4xx — ошибка одного запроса, остальных не блокируем.
     */
    private function markDown(): void
    {
        $this->down = true;
        Cache::put(self::DOWN_KEY, true, self::DOWN_TTL);
    }

    private function gatewayDown(Response $response): never
    {
        $this->markDown();

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
        // Курсор действует только с той же sort: старая закладка даёт 422 лишь по cursor — это не баг клиента.
        $fields = $response->json('error.fields');
        if ($response->status() === 422 && is_array($fields) && array_keys($fields) === ['cursor']) {
            throw new RealEstateStaleCursor('Real Estate: курсор устарел');
        }
        // Район и микрорайон не сходятся (с 29.10): тексты сервера — для человека, это не баг клиента.
        if ($response->status() === 422 && ($rejected = RealEstateSearchRejected::fromFields("Real Estate: HTTP 422 {$path}", $fields))->isAboutPlace()) {
            Log::warning('Real Estate отклонил район и микрорайон', ['path' => $path, 'fields' => $fields]);

            throw $rejected;
        }

        Log::error("Real Estate API: {$response->status()} — ошибка в запросе GS Home", ['path' => $path, 'fields' => $response->json('error.fields')]);

        throw new RealEstateBadRequest("Real Estate: HTTP {$response->status()}");
    }

    private function http(): PendingRequest
    {
        return $this->configured(Http::createPendingRequest());
    }

    private function configured(PendingRequest $request): PendingRequest
    {
        return $request->baseUrl((string) config('services.real_estate.url'))
            ->withToken((string) config('services.real_estate.key'))
            ->acceptJson()
            // Страница на 50 объявлений — ~400 КБ JSON, сжатый — ~70 КБ. Cloudflare и внутренний вход сжимают по запросу.
            ->withOptions(['decode_content' => 'gzip'])
            ->connectTimeout(3)
            ->timeout(10)
            // 503 = query_timeout: API просит не повторять тот же запрос. Две попытки по 10 с — предел ~20 с на вызов,
            // а после первого обрыва остальные вызовы запроса не ходят в сеть (см. $down).
            ->retry(2, 200, fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [500, 502, 504], true)), throw: false);
    }
}
