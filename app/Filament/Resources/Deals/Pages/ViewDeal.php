<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Crm\Deals;
use App\Crm\DealStage;
use App\Crm\DealStages;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Journal;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ViewDeal extends ViewRecord
{
    protected static string $resource = DealResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Deal ? __('gshome.crm.deals.title', ['id' => $record->id]) : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nextStage')
                ->label(function (Deal $record): string {
                    $next = $this->nextStage($record);

                    return __('gshome.crm.deals.next_stage').($next === null ? '' : ': '.$next->label());
                })
                ->visible(fn (Deal $record): bool => $record->isOpen() && $this->can('manage', $record) && $this->nextStage($record) !== null)
                ->action(fn (Deal $record) => $this->perform($record, 'manage', function (Deal $deal, User $user): void {
                    // Следующий этап считается на сервере, а не берётся из запроса.
                    $next = $this->nextStage($deal);
                    if ($next === null) {
                        throw new CrmForbidden('No next stage.');
                    }
                    app(Deals::class)->moveStage($deal, $next->key, $user);
                })),
            Action::make('win')
                ->label(__('gshome.crm.deals.won'))
                ->color('success')
                ->visible(fn (Deal $record): bool => $record->isOpen() && $this->can('manage', $record))
                ->schema([
                    TextInput::make('amount')
                        ->label(__('gshome.crm.deals.amount'))
                        ->required()
                        ->regex('/^\d{1,12}(\.\d{1,2})?$/')
                        ->inputMode('decimal'),
                    Select::make('currency')
                        ->label(__('gshome.crm.deals.currency'))
                        ->options(array_combine((array) config('crm.currencies'), (array) config('crm.currencies')))
                        ->default(config('crm.currencies.0'))
                        ->required(),
                ])
                ->action(fn (Deal $record, array $data) => $this->perform($record, 'manage', fn (Deal $deal, User $user) => app(Deals::class)->win(
                    $deal, (string) $data['amount'], (string) $data['currency'], $user,
                ))),
            Action::make('lose')
                ->label(__('gshome.crm.deals.lost'))
                ->color('danger')
                ->visible(fn (Deal $record): bool => $record->isOpen() && $this->can('manage', $record))
                ->schema([
                    Textarea::make('reason')->label(__('gshome.crm.deals.reason'))->required()->maxLength(1000),
                ])
                ->action(fn (Deal $record, array $data) => $this->perform($record, 'manage', fn (Deal $deal, User $user) => app(Deals::class)->lose(
                    $deal, (string) $data['reason'], $user,
                ))),
            Action::make('archive')
                ->label(__('gshome.crm.clients.to_archive'))
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (Deal $record): bool => $this->can('archive', $record))
                ->action(function (Deal $record): void {
                    $this->authorizeFor('archive', $record);
                    DB::transaction(function () use ($record): void {
                        $record->delete();
                        Journal::record(Journal::ARCHIVED, $record);
                    });
                    // Не владелец после архивации сделку уже не видит.
                    $this->redirect(DealResource::getUrl('index'));
                }),
        ];
    }

    /** Первый видимый обычный этап после текущего. */
    private function nextStage(Deal $deal): ?DealStage
    {
        $current = DealStages::find($deal->stage);
        foreach (DealStages::visible() as $stage) {
            if ($stage->kind === 'normal' && ($current === null || $stage->order > $current->order)) {
                return $stage;
            }
        }

        return null;
    }

    private function can(string $ability, Deal $deal): bool
    {
        return Auth::user()?->can($ability, $deal) ?? false;
    }

    /** Скрытая кнопка — не защита: каждое действие проверяет право заново. */
    private function authorizeFor(string $ability, Deal $deal): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can($ability, $deal), 403);

        return $user;
    }

    /** @param  Closure(Deal, User): mixed  $do */
    private function perform(Deal $deal, string $ability, Closure $do): void
    {
        $user = $this->authorizeFor($ability, $deal);
        try {
            $do($deal, $user);
            Notification::make()->title(__('gshome.crm.pins.closed'))->success()->send();
        } catch (CrmForbidden) {
            Notification::make()->title(__('gshome.crm.deals.forbidden'))->danger()->send();
        }
        $deal->refresh()->unsetRelations();
    }
}
