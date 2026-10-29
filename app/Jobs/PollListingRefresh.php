<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\ListingInfoCollected;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateRefreshNotFound;
use App\RealEstate\ListingRefresh;
use App\RealEstate\RealEstateClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Ждёт конца актуализации объявления и кладёт в колокольчик «информация собрана»: менеджер мог уйти со страницы,
 * и браузерный опрос (pollRefresh) его уже не дождётся. Каждый шаг ставит следующий сам.
 * Повторное нажатие кнопки возвращает тот же id актуализации: второй опрос не ставим (start()).
 * Не ShouldBeUnique: её блокировка держится, пока идёт handle(), и молча отбросила бы следующий шаг,
 * который задача ставит сама себе.
 */
class PollListingRefresh implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Потолок опроса с первой постановки, секунд: если Real Estate молчит, не крутимся вечно. */
    private const CAP_SECONDS = 900;

    public function __construct(
        public readonly string $refreshId,
        public readonly int $listingId,
        public readonly int $userId,
        public readonly int $startedAt,
    ) {}

    /** Поставить опрос, если по этой актуализации он ещё не идёт. false — уже идёт, второго не будет. */
    public static function start(string $refreshId, int $listingId, int $userId): bool
    {
        if (! Cache::add(self::lockKey($refreshId), true, self::CAP_SECONDS + 60)) {
            return false;
        }
        self::dispatch($refreshId, $listingId, $userId, now()->timestamp)->delay(2);

        return true;
    }

    /** Карточка в браузере уже показала итог — уведомление не нужно. */
    public static function markShown(string $refreshId): void
    {
        Cache::put(self::shownKey($refreshId), true, self::CAP_SECONDS + 60);
    }

    private static function shownKey(string $refreshId): string
    {
        return 'listing-refresh-shown:'.$refreshId;
    }

    public static function lockKey(string $refreshId): string
    {
        return 'listing-refresh-poll:'.$refreshId;
    }

    public function handle(RealEstateClient $client): void
    {
        $state = null;
        try {
            $state = $client->refreshState($this->refreshId);
        } catch (RealEstateRefreshNotFound) {
            return; // Real Estate потерял проверку: сообщать не о чем
        } catch (RealEstateException|ConnectionException $e) {
            // Сбой сети или API — не повод бросать: пробуем снова, пока не вышел потолок.
            Log::warning('Опрос актуализации объявления не удался', ['refresh' => $this->refreshId, 'error' => $e->getMessage()]);
        }

        if ($state?->finished()) {
            $this->finish($client, $state);

            return;
        }

        $expired = now()->timestamp - $this->startedAt >= self::CAP_SECONDS
            || ($state?->deadlineAt !== null && now()->greaterThan($state->deadlineAt->copy()->addSeconds(15)));
        if ($expired) {
            return;
        }

        $seconds = $state === null ? 5 : min(10, max(2, (int) ceil($state->pollAfterMs / 1000)));
        self::dispatch($this->refreshId, $this->listingId, $this->userId, $this->startedAt)->delay($seconds);
    }

    private function finish(RealEstateClient $client, ListingRefresh $state): void
    {
        $user = User::query()->find($this->userId);
        $client->forgetCard($this->listingId);
        if ($user === null || Cache::pull(self::shownKey($this->refreshId))) {
            return;
        }

        // Название — на языке человека: у очереди язык процесса.
        $previous = app()->getLocale();
        app()->setLocale($user->locale->value);
        try {
            $title = $client->listing($this->listingId)?->title;
        } catch (RealEstateException|ConnectionException) {
            $title = null;
        } finally {
            app()->setLocale($previous);
        }

        $user->notify(new ListingInfoCollected($this->listingId, $title ?: '#'.$this->listingId, $state->stale()));
    }
}
