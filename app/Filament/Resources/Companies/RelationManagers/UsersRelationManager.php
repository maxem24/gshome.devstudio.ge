<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Люди компании — только список; правка в разделе «Люди». */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Люди';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Имя')->searchable(),
                TextColumn::make('role')->label('Роль')->badge(),
                TextColumn::make('direction')->label('Команда')->placeholder('—'),
                TextColumn::make('teamLead.name')->label('Тимлид')->placeholder('—'),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ]);
    }
}
