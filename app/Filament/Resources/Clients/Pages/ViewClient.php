<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Crm\Journal;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Actions\Action;
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
            Action::make('archive')
                ->label(__('gshome.crm.clients.to_archive'))
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('gshome.crm.clients.archive_description'))
                ->visible(fn (Client $record): bool => ! $record->trashed() && (Auth::user()?->can('archive', $record) ?? false))
                ->action(function (Client $record): void {
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
