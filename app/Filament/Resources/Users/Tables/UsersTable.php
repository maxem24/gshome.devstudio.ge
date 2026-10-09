<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Имя')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('role')->label('Роль')->badge(),
                TextColumn::make('company.name')->label('Компания')->placeholder('—')->sortable(),
                TextColumn::make('direction')->label('Команда')->placeholder('—'),
                TextColumn::make('teamLead.name')->label('Тимлид')->placeholder('—'),
                IconColumn::make('both_directions')->label('Оба направления')->boolean(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->filters([
                SelectFilter::make('role')->label('Роль')->options(Role::class),
                SelectFilter::make('company_id')->label('Компания')->relationship('company', 'name'),
                TernaryFilter::make('is_active')->label('Активен'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
