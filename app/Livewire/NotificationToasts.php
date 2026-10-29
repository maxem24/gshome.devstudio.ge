<?php

namespace App\Livewire;

use App\Notifications\ListingInfoCollected;
use App\Notifications\SiteSearchFinished;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Показывает новые уведомления колокольчика ещё и всплывающим окном на любой странице панели.
 * Колокольчик сам узнаёт о новом только раз в 30 с и без всплытия, а менеджер ждёт ответа сразу.
 * В базу не лезем: уведомление остаётся непрочитанным, тост — лишь копия.
 */
class NotificationToasts extends Component
{
    private const SEEN_KEY = 'toasts.seen_at';

    /** id уже показанных уведомлений с created_at, равным метке. */
    private const SHOWN_KEY = 'toasts.shown_at_mark';

    private const DURATION_MS = 10000;

    /** Типы уведомлений, которые дублируем тостом. */
    private const TYPES = [SiteSearchFinished::class, ListingInfoCollected::class];

    public function mount(): void
    {
        // Метка в сессии: старое никогда не всплывёт, только то, что появилось после открытия панели.
        if (! session()->has(self::SEEN_KEY)) {
            session()->put(self::SEEN_KEY, now()->toIso8601String());
        }
    }

    public function check(): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }
        $seen = Carbon::parse((string) session(self::SEEN_KEY, now()->toIso8601String()));
        /** @var list<string> $shownAtMark уже показанные в секунду метки */
        $shownAtMark = (array) session(self::SHOWN_KEY, []);

        // created_at — с точностью до секунды: «>» потерял бы второе уведомление той же секунды.
        $rows = $user->notifications()
            ->whereIn('type', self::TYPES)
            ->where('created_at', '>=', $seen)
            ->whereNotIn('id', $shownAtMark)
            ->oldest()
            ->limit(3)
            ->get();
        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $row) {
            Notification::fromArray($row->data)->duration(self::DURATION_MS)->send();
        }
        // Метка двигается на последнюю показанную: то, что не влезло в лимит, всплывёт на следующей проверке.
        $mark = $rows->last()->created_at;
        $sameSecond = $rows->filter(fn ($row): bool => $row->created_at->equalTo($mark))->pluck('id')->all();
        session()->put(self::SEEN_KEY, $mark->toIso8601String());
        session()->put(self::SHOWN_KEY, $mark->equalTo($seen) ? [...$shownAtMark, ...$sameSecond] : $sameSecond);
        // Колокольчик обновится сразу, не дожидаясь своих 30 с.
        $this->dispatch('databaseNotificationsSent');
    }

    public function render(): View
    {
        return view('livewire.notification-toasts');
    }
}
