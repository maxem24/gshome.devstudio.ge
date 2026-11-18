<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Crm\ClientPlaces;
use App\Crm\ClientProfile;
use App\Enums\ClientHome;
use App\Enums\ClientHousing;
use App\Enums\ClientOccupant;
use App\Enums\ClientTag;
use App\Models\Client;
use App\Support\PhoneNumber;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
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
            Select::make('housing')
                ->label(__('gshome.crm.clients.housing_label'))
                ->options(ClientHousing::class)
                ->required(),
            Select::make('reliability')
                ->label(__('gshome.crm.clients.reliability'))
                ->options(self::reliabilityOptions())
                ->default('unverified')
                ->required(),
            ...self::profileFields(),
        ]);
    }

    /**
     * Поля «Кого ищет». Те же — в предпочтениях собственника, там «кто будет жить» — несколько вариантов.
     *
     * @return list<Component>
     */
    public static function profileFields(bool $manyOccupants = false): array
    {
        return [
            Select::make('occupants')
                ->label(__('gshome.crm.clients.profile.occupants'))
                ->options(ClientOccupant::class)
                ->multiple($manyOccupants)
                ->native(false)
                ->searchable(),
            TextInput::make('people')->label(__('gshome.crm.clients.profile.people'))->numeric()->minValue(1)->maxValue(30),
            Select::make('home')
                ->label(__('gshome.crm.clients.profile.home'))
                ->options(ClientHome::class)
                ->multiple()
                ->native(false)
                ->searchable(),
            Select::make('subdistrict_ids')
                ->label(__('gshome.crm.clients.profile.place'))
                ->options(fn (): array => ClientPlaces::options())
                ->multiple()
                ->native(false)
                ->searchable(),
            TextInput::make('rooms')->label(__('gshome.crm.clients.profile.rooms'))->maxLength(32),
            TextInput::make('price_min')->label(__('gshome.crm.clients.profile.price_min'))->numeric()->minValue(0),
            TextInput::make('price_max')->label(__('gshome.crm.clients.profile.price_max'))->numeric()->minValue(0),
            Textarea::make('note')->label(__('gshome.crm.clients.profile.note'))->maxLength(2000)->columnSpanFull(),
        ];
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
            TextEntry::make('housing')
                ->label(__('gshome.crm.clients.housing_label'))
                ->badge()
                ->placeholder('—'),
            TextEntry::make('profile')
                ->label(__('gshome.crm.clients.profile_title'))
                ->state(fn (Client $record): array => ClientProfile::lines($record))
                ->listWithLineBreaks()
                ->placeholder('—')
                ->columnSpanFull(),
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
