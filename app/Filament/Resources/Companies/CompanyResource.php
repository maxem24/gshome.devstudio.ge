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
use App\Rules\UniqueCompanyName;
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

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('gshome.nav.org');
    }

    public static function getModelLabel(): string
    {
        return __('gshome.company.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('gshome.company.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('gshome.company.name'))
                ->required()
                ->maxLength(255)
                ->rule(fn (?Company $record) => new UniqueCompanyName($record?->getKey())),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('gshome.company.name'))->searchable()->sortable(),
                TextColumn::make('users_count')->label(__('gshome.company.people_count'))->counts('users'),
                TextColumn::make('phones_count')->label(__('gshome.company.phones_count'))->counts('phones'),
                TextColumn::make('archived_at')->label(__('gshome.company.archived_since'))->date()->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('archived_at')->label(__('gshome.company.archive_filter'))->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('archive')
                    ->label(__('gshome.company.to_archive'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('gshome.company.archive_description'))
                    ->visible(fn (Company $record): bool => ! $record->isArchived() && (Auth::user()?->can('update', $record) ?? false))
                    ->action(fn (Company $record) => $record->update(['archived_at' => now()])),
                Action::make('restore')
                    ->label(__('gshome.company.restore'))
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
