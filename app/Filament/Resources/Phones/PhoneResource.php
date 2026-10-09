<?php

namespace App\Filament\Resources\Phones;

use App\Enums\PhoneKind;
use App\Enums\Role;
use App\Filament\Resources\Phones\Pages\CreatePhone;
use App\Filament\Resources\Phones\Pages\EditPhone;
use App\Filament\Resources\Phones\Pages\ListPhones;
use App\Models\Phone;
use App\Models\User;
use App\Rules\UniquePhone;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

class PhoneResource extends Resource
{
    protected static ?string $model = Phone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = 'Оргструктура';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'номер';

    protected static ?string $pluralModelLabel = 'номера';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            PhoneInput::make('number')
                ->label('Номер')
                ->required()
                // Только Грузия. initialCountry обязателен: без него пакет
                // спрашивает страну у ipinfo.io запросом С СЕРВЕРА — в BigMed
                // прод во Франкфурте показывал всем флаг Германии.
                ->onlyCountries(['GE'])
                ->initialCountry('GE')
                ->defaultCountry('GE')
                ->disableLookup()
                ->allowDropdown(false)
                ->formatAsYouType()
                ->displayNumberFormat(PhoneInputNumberType::INTERNATIONAL)
                ->inputNumberFormat(PhoneInputNumberType::E164)
                ->rule(fn (?Phone $record) => new UniquePhone($record?->getKey())),
            Select::make('kind')
                ->label('Вид')
                ->options(PhoneKind::class)
                ->default(PhoneKind::Personal->value)
                ->required(),
            Select::make('company_id')
                ->label('Компания')
                ->relationship('company', 'name', fn (Builder $query) => $query->whereNull('archived_at'))
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('user_id', null)),
            Select::make('user_id')
                ->label('Держатель')
                ->helperText('Для номера офиса — ответственный. Пусто — на объявлении будет компания и сам номер.')
                ->options(fn (Get $get) => User::query()
                    ->where('company_id', $get('company_id'))
                    ->where('is_active', true)
                    ->where('role', '!=', Role::Owner)
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->placeholder('Без держателя'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Номер')->searchable(),
                TextColumn::make('kind')->label('Вид')->badge(),
                TextColumn::make('company.name')->label('Компания')->sortable(),
                TextColumn::make('holder.name')->label('Держатель')->placeholder('без держателя'),
            ])
            ->filters([
                SelectFilter::make('company_id')->label('Компания')->relationship('company', 'name'),
                SelectFilter::make('kind')->label('Вид')->options(PhoneKind::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** Владельцу — все номера, владельцу компании — своей компании. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        return $user instanceof User && $user->role === Role::Owner
            ? $query
            : $query->where('company_id', $user?->company_id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPhones::route('/'),
            'create' => CreatePhone::route('/create'),
            'edit' => EditPhone::route('/{record}/edit'),
        ];
    }
}
