<?php

namespace App\Search;

use App\Crm\Journal;
use App\Jobs\PollListingSearch;
use App\Models\ListingSearch;
use App\Models\User;
use App\Notifications\SiteSearchFinished;
use App\RealEstate\Exceptions\RealEstateAccessDenied;
use App\RealEstate\Exceptions\RealEstateSearchNotFound;
use App\RealEstate\RealEstateClient;
use App\RealEstate\SiteSearchRequest;
use App\RealEstate\SiteSearchStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Поиск по сайтам: старт, обновление состояния, завершение с уведомлением в колокольчик. */
class SiteSearches
{
    public function __construct(private readonly RealEstateClient $client) {}

    /**
     * Исключения клиента (RealEstate*, CrmForbidden) пробрасываем — экран покажет их человеку.
     *
     * @param  array<string, mixed>  $form
     */
    public function start(User $by, array $form): SearchStart
    {
        $body = SiteSearchRequest::build($by, $form);

        return $this->startWith($by, sha1((string) json_encode($body)), fn (): array => [$this->client->startSearch($body), $form])
            ?? throw new \LogicException('POST /search-jobs всегда даёт поиск');
    }

    /**
     * Старт поиска с защитой от повторов: такой же идёт — он, такой же готов недавно — он, иначе $post.
     * $post шлёт запрос и отдаёт [состояние, фильтры для записи] или null, если поиска не вышло (ссылка на объявление).
     *
     * @param  \Closure(): (array{SiteSearchStatus, array<string, mixed>}|null)  $post
     */
    public function startWith(User $by, string $hash, \Closure $post): ?SearchStart
    {
        // Проверка и создание под advisory-замком: два одновременных старта с теми же фильтрами шлют один POST.
        $start = DB::transaction(function () use ($by, $hash, $post): ?SearchStart {
            DB::select('select pg_advisory_xact_lock(?)', [crc32($by->getKey().':'.$hash)]);

            $same = ListingSearch::query()->where('user_id', $by->getKey())->where('body_hash', $hash);

            $running = (clone $same)->whereIn('status', ListingSearch::RUNNING)->latest('id')->first();
            if ($running !== null && $running->started_at->gt(now()->subMinutes((int) config('search.timeout_minutes', 30)))) {
                return new SearchStart($running, SearchStart::RUNNING);
            }
            if ($running !== null) {
                // Опрос давно умер: поиск «в работе» навсегда заблокировал бы повтор.
                $this->close($running, 'timed_out', __('gshome.search.timed_out', [], $by->locale->value));
            }

            $fresh = (clone $same)->where('status', 'done')
                ->where('finished_at', '>=', now()->subMinutes((int) config('search.reuse_minutes', 30)))
                ->latest('id')->first();
            if ($fresh !== null) {
                return new SearchStart($fresh, SearchStart::REUSED);
            }

            $posted = $post();
            if ($posted === null) {
                return null;
            }
            [$status, $filters] = $posted;

            $search = new ListingSearch([
                'user_id' => $by->getKey(),
                'filters' => $filters,
                'body_hash' => $hash,
                'remote_id' => $status->id,
                'started_at' => now(),
            ]);
            $this->apply($search, $status);
            $search->save();

            Journal::record(Journal::SEARCH_STARTED, $search, [], $by);

            return new SearchStart($search, SearchStart::NEW);
        });

        if ($start === null) {
            return null;
        }
        $search = $start->search;
        if ($start->kind === SearchStart::NEW) {
            if ($search->isRunning()) {
                try {
                    PollListingSearch::dispatch($search->id)->delay((int) config('search.poll_seconds', 10));
                } catch (\Throwable $e) {
                    // Очередь недоступна: без опроса поиск осиротел бы — закрываем и уведомляем, а не отдаём 500.
                    Log::error('Не удалось поставить опрос поиска по сайтам', ['search' => $search->id, 'error' => $e->getMessage()]);
                    $this->close($search, 'failed', __('gshome.search.failed', [], $by->locale->value));
                }
            } else {
                $this->finish($search);
            }
        }

        return $start;
    }

    /** Спрашивает Real Estate о состоянии; закончен — завершает. Потерянный на их стороне поиск (404) — failed. */
    public function refresh(ListingSearch $search): void
    {
        if ($search->remote_id === null) {
            return;
        }

        try {
            $status = $this->client->searchStatus($search->remote_id, $search->user->locale->value);
        } catch (RealEstateSearchNotFound) {
            $this->close($search, 'failed', __('gshome.search.lost', [], $search->user->locale->value));

            return;
        } catch (RealEstateAccessDenied) {
            // Ключ отозван: ждать 30 минут бессмысленно, пока ключ не вернут, поиск не продвинется.
            $this->close($search, 'failed', __('gshome.errors.access_denied', [], $search->user->locale->value));

            return;
        }

        $this->whileRunning($search, fn (ListingSearch $row) => $this->apply($row, $status));

        if (! $search->isRunning()) {
            $this->finish($search);
        }
    }

    /** Поиски, у которых давно нет опроса (очередь потеряла задание): закрывает как timed_out. Возвращает, сколько закрыто. */
    public function sweep(): int
    {
        $stale = ListingSearch::query()->with('user')->whereIn('status', ListingSearch::RUNNING)
            ->where('started_at', '<=', now()->subMinutes((int) config('search.timeout_minutes', 30)))
            ->get();

        foreach ($stale as $search) {
            $this->close($search, 'timed_out', __('gshome.search.timed_out', [], $search->user->locale->value));
        }

        return $stale->count();
    }

    /** Закрывает идущий поиск своим статусом (failed, timed_out) и завершает; уже закрытый не трогает. */
    public function close(ListingSearch $search, string $status, ?string $message = null): void
    {
        $this->whileRunning($search, function (ListingSearch $row) use ($status, $message): void {
            $row->status = $status;
            $row->message = $message ?? $row->message;
            $row->finished_at = now();
        });

        $this->finish($search);
    }

    /** Один раз на поиск, сколько бы раз и откуда ни позвали: решает блокировка строки и notified_at. */
    public function finish(ListingSearch $search): void
    {
        $locked = DB::transaction(function () use ($search): ?ListingSearch {
            $row = ListingSearch::query()->lockForUpdate()->find($search->id);
            if ($row === null || $row->notified_at !== null || $row->isRunning()) {
                return null;
            }
            $row->notified_at = now();
            $row->finished_at ??= now();
            $row->save();

            return $row;
        });

        if ($locked === null) {
            return;
        }

        $search->setRawAttributes($locked->getAttributes(), true);

        $user = $locked->user;
        Journal::record(Journal::SEARCH_FINISHED, $locked, ['status' => $locked->status, 'found' => $locked->found], $user);
        $user->notify(new SiteSearchFinished($locked));
    }

    /** Меняет строку под блокировкой, только если поиск всё ещё идёт: поздний timed_out не затрёт done. */
    private function whileRunning(ListingSearch $search, \Closure $change): void
    {
        DB::transaction(function () use ($search, $change): void {
            $row = ListingSearch::query()->lockForUpdate()->find($search->id);
            if ($row === null) {
                return;
            }
            if ($row->isRunning()) {
                $change($row);
                $row->save();
            }
            $search->setRawAttributes($row->getAttributes(), true);
        });
    }

    private function apply(ListingSearch $search, SiteSearchStatus $status): void
    {
        $search->status = match ($status->status) {
            'done', 'failed', 'cancelled' => $status->status,
            'queued' => $search->exists ? 'running' : 'queued',
            default => 'running',
        };
        $search->message = $status->message;
        $search->sites = $status->sites;
        $search->found = $status->found;
        $search->truncated = $status->truncated;
        $search->listing_ids = $status->listingIds;
        if ($status->isFinished()) {
            $search->finished_at ??= now();
        }
    }
}
