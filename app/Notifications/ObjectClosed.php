<?php

namespace App\Notifications;

use App\Filament\Pages\ListingView;
use App\Models\ListingObject;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Notifications\Notification as BaseNotification;

/** Объект закрыт другим агентом. Формат — база Filament, чтобы лежало в колокольчике. */
class ObjectClosed extends BaseNotification
{
    /** @param  ?string  $name  имя закрывшего; null — смотрящему его видеть нельзя */
    public function __construct(
        public readonly ListingObject $object,
        public readonly string $reason,
        public readonly ?string $name,
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
        $params = [
            'title' => $this->object->title ?? '№'.$this->object->listing_id,
            'name' => $this->name,
        ];

        // won: без имени — «другой компании»; остальные причины — отдельный ключ без имени.
        $key = match ($this->reason) {
            'won' => $this->name === null ? 'closed_by_other_company' : 'closed_by',
            'fake' => $this->name === null ? 'closed_fake_anon' : 'closed_fake',
            default => $this->name === null ? 'closed_rented_elsewhere_anon' : 'closed_rented_elsewhere',
        };

        return Notification::make()
            ->title(__('gshome.crm.objects.'.$key, $params, $locale))
            ->actions([
                Action::make('open')
                    ->label(__('gshome.crm.objects.open', [], $locale))
                    ->url(ListingView::getUrl(['listing' => $this->object->listing_id])),
            ])
            ->getDatabaseMessage();
    }
}
