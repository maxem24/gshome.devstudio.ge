<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use App\Models\User;
use App\Org\FireUser;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

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
                Action::make('fire')
                    ->label('Уволить')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Человек не сможет войти. Встречи и история остаются за ним.')
                    ->visible(fn (User $record): bool => $record->is_active
                        && $record->role !== Role::Owner
                        && (Auth::user()?->can('update', $record) ?? false))
                    ->schema(fn (User $record): array => [
                        Select::make('phone_holder_id')
                            ->label('Кому передать номера')
                            ->options(FireUser::phoneHolderOptions($record))
                            ->placeholder('Оставить без держателя')
                            ->visible($record->phones()->exists()),
                        Select::make('team_lead_id')
                            ->label('Новый тимлид для его сотрудников')
                            ->options(FireUser::teamLeadOptions($record))
                            ->required()
                            ->helperText(FireUser::teamLeadOptions($record) === []
                                ? 'Другого тимлида этой команды нет — сначала заведите его или назначьте.'
                                : null)
                            ->visible($record->agents()->where('is_active', true)->exists()),
                    ])
                    ->action(fn (User $record, array $data) => FireUser::handle(
                        $record,
                        isset($data['phone_holder_id']) ? (int) $data['phone_holder_id'] : null,
                        isset($data['team_lead_id']) ? (int) $data['team_lead_id'] : null,
                    )),
                Action::make('rehire')
                    ->label('Вернуть')
                    ->visible(fn (User $record): bool => ! $record->is_active && (Auth::user()?->can('update', $record) ?? false))
                    ->action(fn (User $record) => $record->update(['is_active' => true])),
            ]);
    }
}
