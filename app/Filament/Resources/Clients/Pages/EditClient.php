<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Crm\ClientProfile;
use App\Crm\Journal;
use App\Enums\ClientHousing;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Правка клиента: имя, пометки, статус и «Кого ищет». Номер здесь не меняется. */
class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $profile = is_array($data['profile'] ?? null) ? $data['profile'] : [];
        $housing = $data['housing'] ?? null;

        return [
            ...$data,
            ...array_intersect_key($profile, array_flip(ClientProfile::KEYS)),
            'housing' => $housing instanceof ClientHousing ? $housing->value : $housing,
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Client $record */
        DB::transaction(function () use ($record, $data): void {
            $record->update([
                'first_name' => $data['first_name'],
                'last_name' => filled($data['last_name'] ?? null) ? $data['last_name'] : null,
                'tags' => array_values($data['tags'] ?? []),
                'reliability' => $data['reliability'],
                'housing' => $data['housing'] ?? null,
                'profile' => ClientProfile::clean($data),
            ]);
            Journal::record(Journal::CLIENT_UPDATED, $record);
        });

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return ClientResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
