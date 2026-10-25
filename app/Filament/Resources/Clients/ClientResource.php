<?php

namespace App\Filament\Resources\Clients;

use App\Filament\RelationManagers\JournalRelationManager;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\DealsRelationManager;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('gshome.crm.nav_group');
    }

    public static function getModelLabel(): string
    {
        return __('gshome.crm.clients.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('gshome.crm.clients.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClientForm::infolist($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    /** Архив видит только владелец с включённым переключателем (Client::visibleTo). */
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        $query = parent::getEloquentQuery();
        if (! $user instanceof User) {
            return $query->whereRaw('false');
        }

        // @phpstan-ignore method.notFound (scopeVisibleTo модели Client; parent отдаёт Builder<Model>)
        return $query->visibleTo($user);
    }

    public static function getRelations(): array
    {
        return [
            DealsRelationManager::class,
            JournalRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
        ];
    }
}
