<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Actions\UserActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Имя')->searchable()->sortable(),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('role')->label('Роль')->badge(),
                TextColumn::make('company.name')->label('Компания')->placeholder('—')->sortable(),
                TextColumn::make('direction')->label('Команда')->placeholder('—'),
                TextColumn::make('teamLead.name')->label('Тимлид')->placeholder('—')->toggleable(),
                TextColumn::make('email')->label('Email')->searchable()->toggleable(),
                IconColumn::make('both_directions')->label('Оба направления')->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Архив по умолчанию скрыт: в работе нужны активные и заблокированные.
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(UserStatus::class)
                    ->multiple()
                    ->default([UserStatus::Active->value, UserStatus::Blocked->value]),
                SelectFilter::make('role')->label('Роль')->options(Role::class),
                SelectFilter::make('company_id')->label('Компания')->relationship('company', 'name'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ...UserActions::all(),
                ]),
            ]);
    }
}
