<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Direction;
use App\Enums\Role;
use App\Models\User;
use App\Rules\UniqueEmail;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Место человека в структуре. Поля, которые роли не положены, скрыты; при
 * сохранении их очищает UserPlacement. «Активен» здесь нет — только через
 * действия «Уволить» / «Вернуть» (Task 9), чтобы номера и сотрудники
 * тимлида не повисли.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Имя')->required()->maxLength(255),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255)
                ->rule(fn (?User $record) => new UniqueEmail($record?->getKey())),
            TextInput::make('password')
                ->label('Пароль')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->hiddenOn('view'),
            Select::make('role')
                ->label('Роль')
                ->options(Role::class)
                ->required()
                ->live()
                ->rule(fn (?User $record): Closure => self::keepsAgents($record, fn (mixed $value): bool => Role::tryFrom((string) $value) !== Role::TeamLead)),
            Select::make('company_id')
                ->label('Компания')
                ->relationship('company', 'name', fn (Builder $query) => $query->whereNull('archived_at'))
                ->visible(fn (Get $get): bool => self::role($get) !== null && self::role($get) !== Role::Owner)
                ->required(fn (Get $get): bool => self::role($get) !== null && self::role($get) !== Role::Owner)
                ->live()
                ->rule(fn (?User $record): Closure => self::keepsAgents($record, fn (mixed $value): bool => (int) $value !== $record?->company_id)),
            Select::make('direction')
                ->label('Команда')
                ->options(Direction::class)
                ->visible(fn (Get $get): bool => self::role($get)?->hasTeam() ?? false)
                ->required(fn (Get $get): bool => self::role($get)?->hasTeam() ?? false)
                ->live()
                ->rule(fn (?User $record): Closure => self::keepsAgents($record, fn (mixed $value): bool => Direction::tryFrom((string) $value) !== $record?->direction)),
            Select::make('team_lead_id')
                ->label('Тимлид')
                ->options(fn (Get $get) => User::query()
                    ->where('role', Role::TeamLead)
                    ->where('is_active', true)
                    ->where('company_id', $get('company_id'))
                    ->where('direction', self::direction($get))
                    ->orderBy('name')
                    ->pluck('name', 'id'))
                ->visible(fn (Get $get): bool => self::role($get) === Role::Agent)
                ->required(fn (Get $get): bool => self::role($get) === Role::Agent),
            Toggle::make('both_directions')
                ->label('Оба направления')
                ->helperText('Видит и продажу, и помесячную сдачу. Людей другой команды не открывает.')
                ->visible(fn (Get $get): bool => self::role($get)?->hasTeam() ?? false),
        ]);
    }

    /**
     * Тимлида с сотрудниками нельзя увести из роли, компании или команды:
     * его сотрудники повисли бы на нём (UserPlacement отвергнет это и сам,
     * здесь — понятная ошибка формы вместо 500).
     *
     * @param  Closure(mixed): bool  $changes
     */
    private static function keepsAgents(?User $record, Closure $changes): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record, $changes): void {
            if ($record?->role === Role::TeamLead && $changes($value) && $record->agents()->exists()) {
                $fail('У тимлида есть сотрудники: сначала переведите их к другому тимлиду.');
            }
        };
    }

    /** Состояние Select бывает enum (из модели) или строкой (из формы). */
    private static function role(Get $get): ?Role
    {
        $value = $get('role');

        return $value instanceof Role ? $value : Role::tryFrom((string) $value);
    }

    private static function direction(Get $get): ?Direction
    {
        $value = $get('direction');

        return $value instanceof Direction ? $value : Direction::tryFrom((string) $value);
    }
}
