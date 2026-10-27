<?php

namespace App\Notifications;

use App\Models\ListingSearch;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Route;

/** Поиск по сайтам закончился. Формат — база Filament, чтобы лежало в колокольчике. */
class SiteSearchFinished extends BaseNotification
{
    /** Страница результата (SearchResult): имя маршрута задаёт Filament по слагу страницы. */
    public const RESULT_ROUTE = 'filament.admin.pages.site-search';

    public function __construct(public readonly ListingSearch $search) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->locale->value;
        $count = $this->search->shown();

        $title = match (true) {
            $this->search->status === 'done' && $this->search->isPartial() => __('gshome.search.done_partial', ['count' => $count], $locale),
            $this->search->status === 'done' => __('gshome.search.done', ['count' => $count], $locale),
            $this->search->status === 'timed_out' => __('gshome.search.timed_out', [], $locale),
            default => __('gshome.search.failed', [], $locale),
        };

        $notification = Notification::make()->title($title);
        if ($this->search->status === 'done') {
            $notification->body($this->search->foundOnSitesNote($locale));
        } elseif ($this->search->message && $this->search->message !== $title) {
            $notification->body($this->search->message);
        }
        if (Route::has(self::RESULT_ROUTE)) {
            $notification->actions([
                Action::make('open')
                    ->label(__('gshome.search.open', [], $locale))
                    ->url(route(self::RESULT_ROUTE, $this->search)),
            ]);
        }

        return $notification->getDatabaseMessage();
    }
}
