<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Notifications\Notification as BaseNotification;

/** Исполнителю поставили задачу. Формат — база Filament, чтобы лежало в его колокольчике. */
class TaskAssigned extends BaseNotification
{
    public function __construct(public readonly Task $task, public readonly User $author) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->locale->value;

        return Notification::make()
            ->title(__('gshome.crm.tasks.assigned', ['author' => $this->author->name], $locale))
            ->body($this->task->body)
            ->getDatabaseMessage();
    }
}
