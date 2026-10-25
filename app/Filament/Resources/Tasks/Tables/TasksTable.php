<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Access\OrgAccess;
use App\Crm\Journal;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TasksTable
{
    public static function configure(Table $table): Table
    {
        $tz = (string) config('crm.timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['assignee', 'author']))
            ->defaultSort('due_at', 'asc')
            ->columns([
                TextColumn::make('body')->label(__('gshome.crm.tasks.body'))->limit(80)->wrap(),
                TextColumn::make('assignee.name')->label(__('gshome.crm.tasks.assignee')),
                TextColumn::make('author.name')->label(__('gshome.crm.tasks.author')),
                TextColumn::make('due_at')->label(__('gshome.crm.tasks.due'))->dateTime('d.m.Y H:i', $tz)->placeholder('—')->sortable(),
                TextColumn::make('status')
                    ->label(__('gshome.crm.tasks.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('gshome.crm.tasks.statuses.'.$state)),
                TextColumn::make('deleted_at')
                    ->label(__('gshome.crm.clients.archived'))
                    ->badge()
                    ->color('danger')
                    ->state(fn (Task $record): ?string => $record->trashed() ? __('gshome.crm.clients.archived') : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('gshome.crm.tasks.status'))
                    ->options(collect((array) config('crm.task_statuses'))
                        ->mapWithKeys(fn (string $s): array => [$s => __('gshome.crm.tasks.statuses.'.$s)])
                        ->all()),
                SelectFilter::make('assignee_id')
                    ->label(__('gshome.crm.tasks.assignee'))
                    ->options(fn (): array => self::assigneeNames()),
            ])
            ->recordActions([
                self::statusAction('done'),
                self::statusAction('cancelled'),
                self::statusAction('open'),
                Action::make('archive')
                    ->label(__('gshome.crm.clients.to_archive'))
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Task $record): bool => Auth::user()?->can('archive', $record) ?? false)
                    ->action(function (Task $record): void {
                        abort_unless(Auth::user()?->can('archive', $record) ?? false, 403);
                        DB::transaction(function () use ($record): void {
                            $record->delete();
                            Journal::record(Journal::ARCHIVED, $record);
                        });
                    }),
            ]);
    }

    /** Кнопка перевода в статус; видна, если статус другой и право есть. */
    private static function statusAction(string $status): Action
    {
        return Action::make('status_'.$status)
            ->label(__('gshome.crm.tasks.to_'.$status))
            ->color($status === 'done' ? 'success' : 'gray')
            ->visible(fn (Task $record): bool => $record->status !== $status && (Auth::user()?->can('changeStatus', $record) ?? false))
            ->action(fn (Task $record) => TaskResource::applyStatus($record, $status));
    }

    /** @return array<int, string> */
    private static function assigneeNames(): array
    {
        $viewer = Auth::user();
        if (! $viewer instanceof User) {
            return [];
        }

        /** @var array<int, string> $names */
        $names = OrgAccess::visibleUsers($viewer)->orderBy('name')->pluck('name', 'id')->all();

        return $names;
    }
}
