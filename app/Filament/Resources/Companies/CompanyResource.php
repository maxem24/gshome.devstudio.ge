<?php

namespace App\Filament\Resources\Companies;

use App\Enums\Role;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Pages\ViewCompany;
use App\Filament\Resources\Companies\RelationManagers\PhonesRelationManager;
use App\Filament\Resources\Companies\RelationManagers\UsersRelationManager;
use App\Models\Company;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Оргструктура';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'компания';

    protected static ?string $pluralModelLabel = 'компании';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Название')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('users_count')->label('Людей')->counts('users'),
                TextColumn::make('phones_count')->label('Номеров')->counts('phones'),
                TextColumn::make('archived_at')->label('В архиве с')->date()->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('archived_at')->label('Архив')->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('archive')
                    ->label('В архив')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Объявления сотрудников компании станут «другой компанией», её люди не смогут войти.')
                    ->visible(fn (Company $record): bool => ! $record->isArchived() && (Auth::user()?->can('update', $record) ?? false))
                    ->action(fn (Company $record) => $record->update(['archived_at' => now()])),
                Action::make('restore')
                    ->label('Вернуть из архива')
                    ->visible(fn (Company $record): bool => $record->isArchived() && (Auth::user()?->can('update', $record) ?? false))
                    ->action(fn (Company $record) => $record->update(['archived_at' => null])),
            ]);
    }

    /** Владельцу — все компании, владельцу компании — своя. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        return $user instanceof User && $user->role === Role::Owner
            ? $query
            : $query->whereKey($user?->company_id);
    }

    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
            PhonesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'view' => ViewCompany::route('/{record}'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
