<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\ListingImported;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\LinkImport;
use App\RealEstate\RealEstateClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Ждёт, пока сборщик откроет карточку по ссылке, и кладёт итог в колокольчик (со ссылкой на карточку или с причиной).
 * Окно «По ссылке» к этому времени закрыто. Устроена как PollListingRefresh: шаг ставит следующий сам,
 * повтор той же ссылки не ставит второй опрос (замок в кеше), не ShouldBeUnique — та сбросила бы следующий шаг.
 */
class PollListingImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Потолок опроса с первой постановки, секунд: если Real Estate молчит, не крутимся вечно.
     * Худший путь у Real Estate: до 10 минут в очереди без сборщика, 1–3 минуты сбор, до 3 минут повтор сохранения.
     */
    private const CAP_SECONDS = 1200;

    private const STEP_SECONDS = 10;

    public function __construct(
        public readonly string $importId,
        public readonly int $userId,
        public readonly string $url,
        public readonly int $startedAt,
    ) {}

    /** Поставить опрос, если по этому импорту он ещё не идёт. false — уже идёт. */
    public static function start(string $importId, int $userId, string $url): bool
    {
        if (! Cache::add(self::lockKey($importId, $userId), true, self::CAP_SECONDS + 60)) {
            return false;
        }
        self::dispatch($importId, $userId, $url, now()->timestamp)->delay(self::STEP_SECONDS);

        return true;
    }

    /** У каждого человека свой замок: двое вставили одну ссылку — уведомление получат оба. */
    public static function lockKey(string $importId, int $userId): string
    {
        return "listing-import-poll:{$importId}:{$userId}";
    }

    public function handle(RealEstateClient $client): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        // Тексты Real Estate (message) — на языке человека: у очереди язык процесса.
        $previous = app()->getLocale();
        app()->setLocale($user->locale->value);
        try {
            $state = $client->importState($this->importId);
            if ($state === null) {
                $user->notify(new ListingImported($this->url, null, __('gshome.link.lost')));

                return;
            }
        } catch (RealEstateException|ConnectionException $e) {
            // Сбой сети или API — не повод бросать: пробуем снова, пока не вышел потолок.
            Log::warning('Опрос ссылки на объявление не удался', ['import' => $this->importId, 'error' => $e->getMessage()]);
            $state = null;
        } finally {
            app()->setLocale($previous);
        }

        if ($state?->finished()) {
            $this->finish($user, $client, $state);

            return;
        }

        if (now()->timestamp - $this->startedAt >= self::CAP_SECONDS) {
            $user->notify(new ListingImported($this->url, null, __('gshome.link.timed_out', [], $user->locale->value)));

            return;
        }

        self::dispatch($this->importId, $this->userId, $this->url, $this->startedAt)->delay(self::STEP_SECONDS);
    }

    private function finish(User $user, RealEstateClient $client, LinkImport $state): void
    {
        if (! $state->ready()) {
            $user->notify(new ListingImported($this->url, null, $state->message !== '' ? $state->message : __('gshome.link.failed', [], $user->locale->value)));

            return;
        }

        $previous = app()->getLocale();
        app()->setLocale($user->locale->value);
        try {
            $title = $client->listing((int) $state->listingId)?->title;
        } catch (RealEstateException|ConnectionException) {
            $title = null;
        } finally {
            app()->setLocale($previous);
        }

        $user->notify(new ListingImported($this->url, $state->listingId, null, $title ?: '#'.$state->listingId));
    }
}
