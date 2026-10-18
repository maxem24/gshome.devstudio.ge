<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Номера компании — только список; правка в разделе «Номера». */
class PhonesRelationManager extends RelationManager
{
    protected static string $relationship = 'phones';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('gshome.company.phones');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')->label(__('gshome.phone.number')),
                TextColumn::make('kind')->label(__('gshome.phone.kind'))->badge(),
                TextColumn::make('holder.name')->label(__('gshome.phone.holder'))->placeholder(__('gshome.phone.no_holder')),
            ]);
    }
}
