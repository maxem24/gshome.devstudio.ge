<?php

namespace App\Notifications;

use App\Models\User;
use App\Search\LinkImports;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Notifications\Notification as BaseNotification;

/** Объявление по ссылке: сборщик открыл карточку (ссылка на неё) или не смог (причина и исходная ссылка). Колокольчик Filament. */
class ListingImported extends BaseNotification
{
    public function __construct(
        public readonly string $url,
        public readonly ?int $listingId,
        public readonly ?string $reason = null,
        public readonly string $title = '',
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->locale->value;

        if ($this->listingId === null) {
            return Notification::make()
                ->danger()
                ->title(__('gshome.link.not_imported', [], $locale))
                ->body($this->reason)
                ->actions([Action::make('source')->label(__('gshome.link.open_source', [], $locale))->url($this->url, shouldOpenInNewTab: true)])
                ->getDatabaseMessage();
        }

        return Notification::make()
            ->success()
            ->title(__('gshome.link.imported', ['title' => $this->title], $locale))
            ->actions([
                Action::make('open')
                    ->label(__('gshome.listings.refresh.open', [], $locale))
                    ->url(LinkImports::cardUrl($notifiable, $this->listingId)),
            ])
            ->getDatabaseMessage();
    }
}
