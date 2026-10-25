<?php

namespace App\Filament\Resources\Tasks;

use App\Access\OrgAccess;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Tasks;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('gshome.crm.nav_group');
    }

    public static function getModelLabel(): string
    {
        return __('gshome.crm.tasks.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('gshome.crm.tasks.title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Агенту поле скрыто: исполнитель — он сам (CreateTask подставляет).
            Select::make('assignee_id')
                ->label(__('gshome.crm.tasks.assignee'))
                ->options(fn (): array => self::assigneeOptions())
                ->searchable()
                ->required()
                ->visible(fn (): bool => ! self::isAgent()),
            Textarea::make('body')->label(__('gshome.crm.tasks.body'))->required()->maxLength(2000),
            DateTimePicker::make('due_at')->label(__('gshome.crm.tasks.due'))->seconds(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return TasksTable::configure($table);
    }

    /** Задачи видимых по ступени исполнителей; архив — по правилу Archive (Task::visibleTo). */
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        $query = parent::getEloquentQuery();
        if (! $user instanceof User) {
            return $query->whereRaw('false');
        }

        // @phpstan-ignore method.notFound (scopeVisibleTo модели Task; parent отдаёт Builder<Model>)
        return $query->visibleTo($user);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
        ];
    }

    /** @return array<int, string> активные люди, которым смотрящий вправе ставить задачи */
    public static function assigneeOptions(): array
    {
        $viewer = Auth::user();
        if (! $viewer instanceof User) {
            return [];
        }

        /** @var array<int, string> $options */
        $options = OrgAccess::visibleUsers($viewer)->where('status', UserStatus::Active->value)->orderBy('name')->pluck('name', 'id')->all();

        return $options;
    }

    public static function isAgent(): bool
    {
        return Auth::user()?->role === Role::Agent;
    }

    /**
     * Смена статуса для таблицы и «Моих задач»: право проверяется заново на сервере,
     * скрытая кнопка не защита. Чужая задача — 403, ничего не меняется.
     */
    public static function applyStatus(Task $task, string $status): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('changeStatus', $task), 403);

        try {
            app(Tasks::class)->setStatus($task, $status, $user);
        } catch (CrmForbidden) {
            Notification::make()->title(__('gshome.crm.tasks.forbidden'))->danger()->send();
        }
    }
}
