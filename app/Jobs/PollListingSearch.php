<?php

namespace App\Jobs;

use App\Models\ListingSearch;
use App\RealEstate\Exceptions\RealEstateException;
use App\Search\SiteSearches;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/** Опрашивает Real Estate, пока поиск не закончится или не истечёт время; каждый шаг ставит следующий сам. */
class PollListingSearch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $searchId) {}

    public function handle(SiteSearches $searches): void
    {
        $search = ListingSearch::query()->with('user')->find($this->searchId);
        if ($search === null || ! $search->isRunning()) {
            return;
        }

        if ($search->started_at->lte(now()->subMinutes((int) config('search.timeout_minutes', 30)))) {
            $searches->close($search, 'timed_out', __('gshome.search.timed_out', [], $search->user->locale->value));

            return;
        }

        try {
            $searches->refresh($search);
        } catch (RealEstateException|ConnectionException $e) {
            // Сбой сети или API — не повод ронять поиск: попробуем снова, пока не истечёт время.
            Log::warning('Опрос поиска по сайтам не удался', ['search' => $search->id, 'error' => $e->getMessage()]);
        }

        if ($search->isRunning()) {
            self::dispatch($search->id)->delay((int) config('search.poll_seconds', 10));
        }
    }

    /** Непойманная ошибка не должна оставлять поиск «в работе» навсегда: закрываем его как failed и уведомляем. */
    public function failed(\Throwable $e): void
    {
        Log::error('Опрос поиска по сайтам упал', ['search' => $this->searchId, 'error' => $e->getMessage()]);

        $search = ListingSearch::query()->find($this->searchId);
        if ($search !== null && $search->isRunning()) {
            app(SiteSearches::class)->close($search, 'failed', __('gshome.search.failed'));
        }
    }
}
