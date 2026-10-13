<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Люди компании — только список; правка в разделе «Люди». */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('gshome.company.people');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label(__('gshome.user.name'))->searchable(),
                TextColumn::make('role')->label(__('gshome.user.role'))->badge(),
                TextColumn::make('direction')->label(__('gshome.user.team'))->placeholder('—'),
                TextColumn::make('teamLead.name')->label(__('gshome.user.team_lead'))->placeholder('—'),
                TextColumn::make('status')->label(__('gshome.user.status'))->badge(),
            ]);
    }
}
