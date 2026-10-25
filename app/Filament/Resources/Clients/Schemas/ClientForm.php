<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\ClientTag;
use App\Models\Client;
use App\Support\PhoneNumber;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/** Создание клиента (редактирования нет) и карточка. */
class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('phone')
                ->label(__('gshome.crm.clients.phone'))
                ->tel()
                ->required()
                ->maxLength(32)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (PhoneNumber::tryNormalize((string) $value) === null) {
                        $fail(__('gshome.crm.clients.phone_invalid'));
                    }
                }),
            TextInput::make('first_name')->label(__('gshome.crm.clients.first_name'))->required()->maxLength(255),
            TextInput::make('last_name')->label(__('gshome.crm.clients.last_name'))->maxLength(255),
            CheckboxList::make('tags')
                ->label(__('gshome.crm.clients.tags'))
                ->options(ClientTag::class)
                ->columns(2),
            Select::make('reliability')
                ->label(__('gshome.crm.clients.reliability'))
                ->options(self::reliabilityOptions())
                ->default('unverified')
                ->required(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('phone_e164')->label(__('gshome.crm.clients.phone'))->placeholder('—'),
            TextEntry::make('first_name')->label(__('gshome.crm.clients.first_name')),
            TextEntry::make('last_name')->label(__('gshome.crm.clients.last_name'))->placeholder('—'),
            TextEntry::make('tags')
                ->label(__('gshome.crm.clients.tags'))
                ->badge()
                ->state(fn (Client $record): array => $record->tagEnums())
                ->placeholder('—'),
            TextEntry::make('reliability')
                ->label(__('gshome.crm.clients.reliability'))
                ->badge()
                ->formatStateUsing(fn (?string $state): string => self::reliabilityLabel($state)),
            TextEntry::make('creator.name')->label(__('gshome.crm.clients.created_by'))->placeholder('—'),
            TextEntry::make('deleted_at')
                ->label(__('gshome.crm.clients.archived'))
                ->dateTime('d.m.Y H:i')
                ->visible(fn (Client $record): bool => $record->trashed()),
        ])->columns(2);
    }

    /** @return array<string, string> */
    public static function reliabilityOptions(): array
    {
        $options = [];
        foreach ((array) config('crm.reliability') as $key) {
            $options[(string) $key] = self::reliabilityLabel((string) $key);
        }

        return $options;
    }

    public static function reliabilityLabel(?string $key): string
    {
        return $key === null ? '—' : __('gshome.crm.clients.reliability_values.'.$key);
    }
}
