<?php

namespace App\Filament\Resources\Deals;

use App\Access\OrgAccess;
use App\Crm\Archive;
use App\Filament\RelationManagers\JournalRelationManager;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\RelationManagers\ViewingsRelationManager;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Filament\Resources\Deals\Tables\DealsTable;
use App\Models\Deal;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class DealResource extends Resource
{
    protected static ?string $model = Deal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('gshome.crm.nav_group');
    }

    public static function getModelLabel(): string
    {
        return __('gshome.crm.deals.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('gshome.crm.deals.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return DealInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DealsTable::configure($table);
    }

    /** Сделки видимых по ступени людей (как владельцев и как партнёров); архив — только владельцу с включённым переключателем. */
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        $query = parent::getEloquentQuery();
        if (! $user instanceof User) {
            return $query->whereRaw('false');
        }

        $visible = OrgAccess::visibleUsers($user)->select('id');

        // Владелец или партнёр сделки среди видимых людей.
        return Archive::scope(
            $query->where(fn (Builder $q) => $q->whereIn('user_id', $visible)->orWhereIn('partner_user_id', $visible)),
            $user,
        );
    }

    public static function getRelations(): array
    {
        return [
            ViewingsRelationManager::class,
            JournalRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeals::route('/'),
            'view' => ViewDeal::route('/{record}'),
        ];
    }
}
