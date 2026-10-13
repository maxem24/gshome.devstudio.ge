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
                TextColumn::make('name')->label(__('gshome.user.name'))->searchable()->sortable(),
                TextColumn::make('status')->label(__('gshome.user.status'))->badge(),
                TextColumn::make('role')->label(__('gshome.user.role'))->badge(),
                TextColumn::make('company.name')->label(__('gshome.user.company'))->placeholder('—')->sortable(),
                TextColumn::make('direction')->label(__('gshome.user.team'))->placeholder('—'),
                TextColumn::make('teamLead.name')->label(__('gshome.user.team_lead'))->placeholder('—')->toggleable(),
                TextColumn::make('email')->label('Email')->searchable()->toggleable(),
                IconColumn::make('both_directions')->label(__('gshome.user.both'))->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Архив по умолчанию скрыт: в работе нужны активные и заблокированные.
                SelectFilter::make('status')
                    ->label(__('gshome.user.status'))
                    ->options(UserStatus::class)
                    ->multiple()
                    ->default([UserStatus::Active->value, UserStatus::Blocked->value]),
                SelectFilter::make('role')->label(__('gshome.user.role'))->options(Role::class),
                SelectFilter::make('company_id')->label(__('gshome.user.company'))->relationship('company', 'name'),
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
