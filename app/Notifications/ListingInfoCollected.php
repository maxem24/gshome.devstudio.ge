<?php

namespace App\Notifications;

use App\Filament\Pages\ListingView;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Notifications\Notification as BaseNotification;

/** Сбор полной информации по объявлению закончился. Формат — база Filament, чтобы лежало в колокольчике. */
class ListingInfoCollected extends BaseNotification
{
    public function __construct(
        public readonly int $listingId,
        public readonly string $title,
        public readonly bool $stale = false,
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
        $key = $this->stale ? 'collected_partial' : 'collected';

        return Notification::make()
            ->title(__('gshome.listings.refresh.'.$key, ['title' => $this->title], $locale))
            ->actions([
                Action::make('open')
                    ->label(__('gshome.listings.refresh.open', [], $locale))
                    ->url(ListingView::getUrl(['listing' => $this->listingId])),
            ])
            ->getDatabaseMessage();
    }
}
