<?php

namespace App\Filament\Resources\Deals\RelationManagers;

use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Viewings;
use App\Enums\ViewingOutcome;
use App\Models\Deal;
use App\Models\User;
use App\Models\Viewing;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Показы по сделке: назначить, перенести, внести итог. Время вводится в часовом поясе CRM. */
class ViewingsRelationManager extends RelationManager
{
    protected static string $relationship = 'viewings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('gshome.crm.deals.viewings.title');
    }

    /** Права на вкладку — права на сделку (DealPolicy); у показов своей политики нет. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    /** Кнопки не прячем «только чтением»: каждое действие проверяет право на сделку само. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $tz = (string) config('crm.timezone');

        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label(__('gshome.crm.deals.viewings.scheduled_at'))
                    ->dateTime('d.m.Y H:i', $tz)
                    ->sortable(),
                TextColumn::make('notes')->label(__('gshome.crm.deals.viewings.notes'))->placeholder('—'),
                TextColumn::make('outcome')
                    ->label(__('gshome.crm.deals.viewings.outcome'))
                    ->badge()
                    ->placeholder(__('gshome.crm.clients.history.no_outcome')),
            ])
            ->headerActions([
                Action::make('schedule')
                    ->label(__('gshome.crm.deals.viewings.schedule'))
                    ->visible(fn (): bool => $this->canManageDeal())
                    ->schema([
                        DateTimePicker::make('at')->label(__('gshome.crm.deals.viewings.scheduled_at'))->seconds(false)->required(),
                        Textarea::make('notes')->label(__('gshome.crm.deals.viewings.notes'))->maxLength(1000),
                    ])
                    ->action(fn (array $data) => $this->perform($this->deal(), fn (User $user) => app(Viewings::class)->schedule(
                        $this->deal(),
                        self::parse((string) $data['at']),
                        filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                        $user,
                    ))),
            ])
            ->recordActions([
                Action::make('reschedule')
                    ->label(__('gshome.crm.deals.viewings.reschedule'))
                    ->visible(fn (Viewing $record): bool => $this->canManageDeal())
                    ->fillForm(fn (Viewing $record): array => ['at' => $record->scheduled_at->timezone($tz)->format('Y-m-d H:i:s')])
                    ->schema([
                        DateTimePicker::make('at')->label(__('gshome.crm.deals.viewings.scheduled_at'))->seconds(false)->required(),
                    ])
                    ->action(fn (Viewing $record, array $data) => $this->perform($record, fn (User $user) => app(Viewings::class)->reschedule(
                        $record,
                        self::parse((string) $data['at']),
                        $user,
                    ))),
                Action::make('outcome')
                    ->label(__('gshome.crm.deals.viewings.record_outcome'))
                    ->visible(fn (Viewing $record): bool => $this->canManageDeal())
                    ->schema([
                        Select::make('outcome')->label(__('gshome.crm.deals.viewings.outcome'))->options(ViewingOutcome::class)->required(),
                    ])
                    ->action(fn (Viewing $record, array $data) => $this->perform($record, fn (User $user) => app(Viewings::class)->recordOutcome(
                        $record,
                        $data['outcome'] instanceof ViewingOutcome ? $data['outcome'] : ViewingOutcome::from((string) $data['outcome']),
                        $user,
                    ))),
            ]);
    }

    private function deal(): Deal
    {
        $deal = $this->getOwnerRecord();
        assert($deal instanceof Deal);

        return $deal;
    }

    private function canManageDeal(): bool
    {
        $deal = $this->deal();

        return $deal->isOpen() && (Auth::user()?->can('manage', $deal) ?? false);
    }

    /** Введённое время — по часовому поясу CRM. */
    private static function parse(string $value): Carbon
    {
        return Carbon::parse($value, config('crm.timezone'));
    }

    /**
     * Скрытая кнопка — не защита: право проверяется заново, а показ берётся только из сделки,
     * чья вкладка открыта (чужой id в таблице не найдётся).
     *
     * @param  Closure(User): mixed  $do
     */
    private function perform(Deal|Viewing $target, Closure $do): void
    {
        $deal = $this->deal();
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('manage', $deal), 403);
        abort_if($target instanceof Viewing && $target->deal_id !== $deal->id, 403);

        try {
            $do($user);
            Notification::make()->title(__('gshome.crm.pins.closed'))->success()->send();
        } catch (CrmForbidden) {
            Notification::make()->title(__('gshome.crm.deals.forbidden'))->danger()->send();
        }
    }
}
