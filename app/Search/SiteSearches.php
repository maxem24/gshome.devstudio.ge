<?php

namespace App\Search;

use App\Crm\Journal;
use App\Jobs\PollListingSearch;
use App\Models\ListingSearch;
use App\Models\User;
use App\Notifications\SiteSearchFinished;
use App\RealEstate\Exceptions\RealEstateBadRequest;
use App\RealEstate\RealEstateClient;
use App\RealEstate\SiteSearchRequest;
use App\RealEstate\SiteSearchStatus;
use Illuminate\Support\Facades\DB;

/** Поиск по сайтам: старт, обновление состояния, завершение с уведомлением в колокольчик. */
class SiteSearches
{
    public function __construct(private readonly RealEstateClient $client) {}

    /**
     * Исключения клиента (RealEstate*, CrmForbidden) пробрасываем — экран покажет их человеку.
     *
     * @param  array<string, mixed>  $form
     */
    public function start(User $by, array $form): ListingSearch
    {
        $body = SiteSearchRequest::build($by, $form);
        $hash = sha1((string) json_encode($body));

        $same = ListingSearch::query()->where('user_id', $by->getKey())->where('body_hash', $hash);

        $running = (clone $same)->whereIn('status', ListingSearch::RUNNING)->latest('id')->first();
        if ($running !== null) {
            return $running;
        }
        $fresh = (clone $same)->where('status', 'done')
            ->where('finished_at', '>=', now()->subMinutes((int) config('search.reuse_minutes', 30)))
            ->latest('id')->first();
        if ($fresh !== null) {
            return $fresh;
        }

        $status = $this->client->startSearch($body);

        $search = new ListingSearch([
            'user_id' => $by->getKey(),
            'filters' => $form,
            'body_hash' => $hash,
            'remote_id' => $status->id,
            'started_at' => now(),
        ]);
        $this->apply($search, $status);
        $search->save();

        Journal::record(Journal::SEARCH_STARTED, $search, [], $by);

        if ($search->isRunning()) {
            PollListingSearch::dispatch($search->id)->delay((int) config('search.poll_seconds', 10));
        } else {
            $this->finish($search);
        }

        return $search;
    }

    /** Спрашивает Real Estate о состоянии; закончен — завершает. Потерянный на их стороне поиск — failed. */
    public function refresh(ListingSearch $search): void
    {
        if ($search->remote_id === null) {
            return;
        }

        try {
            $status = $this->client->searchStatus($search->remote_id);
        } catch (RealEstateBadRequest) {
            $this->close($search, 'failed', __('gshome.search.lost', [], $search->user->locale->value));

            return;
        }

        $this->apply($search, $status);
        $search->save();

        if (! $search->isRunning()) {
            $this->finish($search);
        }
    }

    /** Закрывает поиск своим статусом (failed, timed_out) и завершает. */
    public function close(ListingSearch $search, string $status, ?string $message = null): void
    {
        $search->status = $status;
        $search->message = $message ?? $search->message;
        $search->finished_at = now();
        $search->save();

        $this->finish($search);
    }

    /** Один раз на поиск, сколько бы раз и откуда ни позвали: решает блокировка строки и notified_at. */
    public function finish(ListingSearch $search): void
    {
        $locked = DB::transaction(function () use ($search): ?ListingSearch {
            $row = ListingSearch::query()->lockForUpdate()->find($search->id);
            if ($row === null || $row->notified_at !== null) {
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
