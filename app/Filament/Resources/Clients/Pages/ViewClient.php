<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Crm\Journal;
use App\Enums\ClientHousing;
use App\Enums\DealStatus;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Client ? trim($record->first_name.' '.$record->last_name) : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (Client $record): bool => ! $record->trashed() && (Auth::user()?->can('update', $record) ?? false)),
            Action::make('housing')
                ->label(__('gshome.crm.clients.housing_label'))
                ->visible(fn (Client $record): bool => ! $record->trashed())
                ->fillForm(fn (Client $record): array => ['housing' => $record->housing?->value])
                ->schema([
                    Select::make('housing')
                        ->label(__('gshome.crm.clients.housing_label'))
                        ->options(ClientHousing::class)
                        ->required(),
                ])
                ->action(function (Client $record, array $data): void {
                    // Select с options(enum) отдаёт сам enum, не строку. (string) на нём — 500.
                    $housing = $data['housing'];
                    $record->housing = $housing instanceof ClientHousing
                        ? $housing
                        : ClientHousing::from((string) $housing);
                    $record->save();
                }),
            Action::make('archive')
                ->label(__('gshome.crm.clients.to_archive'))
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('gshome.crm.clients.archive_description'))
                ->visible(fn (Client $record): bool => ! $record->trashed() && (Auth::user()?->can('offerArchive', $record) ?? false))
                ->action(function (Client $record): void {
                    // Кнопка видна и при открытых сделках: отказываем здесь, с пояснением.
                    if ($record->deals()->where('status', DealStatus::Open)->exists()) {
                        Notification::make()->title(__('gshome.crm.clients.has_open_deals'))->danger()->send();

                        return;
                    }
                    abort_unless(Auth::user()?->can('archive', $record) ?? false, 403);
                    DB::transaction(function () use ($record): void {
                        $record->delete();
                        Journal::record(Journal::ARCHIVED, $record);
                    });
                    // Не владелец после архивации карточку уже не видит.
                    $this->redirect(ClientResource::getUrl('index'));
                }),
            Action::make('restore')
                ->label(__('gshome.crm.clients.restore'))
                ->visible(fn (Client $record): bool => $record->trashed() && (Auth::user()?->can('restore', $record) ?? false))
                ->action(function (Client $record): void {
                    abort_unless(Auth::user()?->can('restore', $record) ?? false, 403);
                    DB::transaction(function () use ($record): void {
                        $record->restore();
                        Journal::record(Journal::RESTORED, $record);
                    });
                    $this->refreshFormData(['deleted_at']);
                }),
        ];
    }
}
