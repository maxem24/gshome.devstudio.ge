<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Номера компании — только список; правка в разделе «Номера». */
class PhonesRelationManager extends RelationManager
{
    protected static string $relationship = 'phones';

    protected static ?string $title = 'Номера';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')->label('Номер'),
                TextColumn::make('kind')->label('Вид')->badge(),
                TextColumn::make('holder.name')->label('Держатель')->placeholder('без держателя'),
            ]);
    }
}
