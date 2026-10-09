<?php

namespace App\Filament\Resources\CompanyExchanges;

use App\Filament\Resources\CompanyExchanges\Pages\ManageCompanyExchanges;
use App\Models\CompanyExchange;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Обмен данными сотрудников между двумя компаниями группы (ТЗ 5.7).
 * Включён — сотрудники обеих видят друг друга на объявлениях и могут связаться.
 */
class CompanyExchangeResource extends Resource
{
    protected static ?string $model = CompanyExchange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Оргструктура';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'пара обмена';

    protected static ?string $pluralModelLabel = 'обмен между компаниями';

    protected static ?string $navigationLabel = 'Обмен между компаниями';

    public static function form(Schema $schema): Schema
    {
        $activeCompanies = fn (Builder $query) => $query->whereNull('archived_at');

        return $schema->components([
            Select::make('company_a_id')
                ->label('Компания')
                ->relationship('companyA', 'name', $activeCompanies)
                ->required()
                ->disabledOn('edit'),
            Select::make('company_b_id')
                ->label('Вторая компания')
                ->relationship('companyB', 'name', $activeCompanies)
                ->required()
                ->disabledOn('edit')
                ->different('company_a_id')
                ->rule(fn (Get $get, ?CompanyExchange $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    $a = (int) $get('company_a_id');
                    if ($a !== 0 && CompanyExchange::existsBetween($a, (int) $value, $record?->getKey())) {
                        $fail('Пара этих компаний уже есть.');
                    }
                }),
            Toggle::make('enabled')->label('Обмен включён')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('companyA.name')->label('Компания'),
                TextColumn::make('companyB.name')->label('Вторая компания'),
                IconColumn::make('enabled')->label('Обмен')->boolean(),
                TextColumn::make('updated_at')->label('Изменено')->dateTime(),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (CompanyExchange $record): string => $record->enabled ? 'Выключить' : 'Включить')
                    ->requiresConfirmation()
                    ->action(fn (CompanyExchange $record) => $record->update(['enabled' => ! $record->enabled])),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompanyExchanges::route('/'),
        ];
    }
}
