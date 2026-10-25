<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Tasks;
use App\Enums\Role;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    /** Исполнитель агента — он сам, что бы ни пришло в форме; остальных проверяет Tasks::create (canActFor). */
    protected function handleRecordCreation(array $data): Model
    {
        $author = Auth::user();
        assert($author instanceof User);

        $assignee = $author->role === Role::Agent
            ? $author
            : User::query()->findOrFail((int) ($data['assignee_id'] ?? 0));
        // Время вводят по поясу CRM; в форме оно уже в поясе приложения, поэтому читаем как строку.
        $due = filled($data['due_at'] ?? null)
            ? Carbon::parse((string) $data['due_at'], config('crm.timezone'))
            : null;

        try {
            return app(Tasks::class)->create($author, $assignee, (string) $data['body'], $due);
        } catch (CrmForbidden) {
            Notification::make()->title(__('gshome.crm.tasks.forbidden'))->danger()->send();
            $this->halt();
        }

        return new Task;
    }

    protected function getRedirectUrl(): string
    {
        return TaskResource::getUrl('index');
    }
}
