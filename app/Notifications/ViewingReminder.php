<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Viewing;
use Filament\Notifications\Notification;
use Illuminate\Notifications\Notification as BaseNotification;

/** Напоминание агенту о показе. Без имени и телефона клиента — только объект и время. */
class ViewingReminder extends BaseNotification
{
    public const BEFORE = 'before';

    public const DAY_OF = 'day_of';

    public const OVERDUE = 'overdue';

    public function __construct(public readonly Viewing $viewing, public readonly string $kind) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->locale->value;
        $at = $this->viewing->scheduled_at->copy()->timezone(config('crm.timezone'));

        return Notification::make()
            ->title(__('gshome.crm.reminders.'.$this->kind, [
                'time' => $at->format('H:i'),
                'date' => $at->format('d.m.Y'),
                'listing' => $this->viewing->deal->pin->listing_id,
            ], $locale))
            ->warning()
            ->getDatabaseMessage();
    }
}
