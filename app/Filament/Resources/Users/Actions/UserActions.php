<?php

namespace App\Filament\Resources\Users\Actions;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Org\UserLifecycle;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Действия над аккаунтом — одни и те же в меню строки таблицы и в шапке
 * карточки человека. Логика — в UserLifecycle; здесь только экран.
 */
final class UserActions
{
    /** @return array<int, Action> */
    public static function all(): array
    {
        return [
            self::changePassword(),
            self::block(),
            self::unblock(),
            self::archive(),
            self::restore(),
        ];
    }

    public static function changePassword(): Action
    {
        return Action::make('changePassword')
            ->label('Сменить пароль')
            ->icon(Heroicon::OutlinedKey)
            ->modalHeading(fn (User $record): string => "Сменить пароль: {$record->name}")
            ->modalDescription('Открытые сессии человека закроются, «Запомнить меня» перестанет работать.')
            ->modalSubmitActionLabel('Сменить')
            ->visible(fn (User $record): bool => self::canManage($record) && ! $record->isArchived())
            ->schema([
                TextInput::make('password')
                    ->label('Новый пароль')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(UserLifecycle::MIN_PASSWORD)
                    ->confirmed()
                    ->suffixAction(
                        Action::make('generate')
                            ->label('Сгенерировать')
                            ->icon(Heroicon::OutlinedSparkles)
                            ->action(function (Set $set): void {
                                $password = UserLifecycle::generatePassword();
                                $set('password', $password);
                                $set('password_confirmation', $password);
                            }),
                    ),
                TextInput::make('password_confirmation')
                    ->label('Повторите пароль')
                    ->password()
                    ->revealable()
                    ->required()
                    ->dehydrated(false),
            ])
            ->action(function (User $record, array $data): void {
                UserLifecycle::changePassword($record, $data['password']);
                // Пароль показываем: сгенерированный в поле скрыт точками, и
                // без этого владелец не узнал бы, что передавать человеку.
                Notification::make()->success()->persistent()
                    ->title('Пароль изменён')
                    ->body("Вход: {$record->email}\nПароль: {$data['password']}")
                    ->send();
            });
    }

    public static function block(): Action
    {
        return Action::make('block')
            ->label('Заблокировать')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => "Заблокировать: {$record->name}")
            ->modalDescription('Войти не сможет со следующего действия. Номера, сотрудники, место в команде и имя на объявлениях остаются. Разблокировать можно в любой момент.')
            ->visible(fn (User $record): bool => self::canManage($record) && $record->isActive() && $record->role !== Role::Owner)
            ->action(fn (User $record) => UserLifecycle::block($record));
    }

    public static function unblock(): Action
    {
        return Action::make('unblock')
            ->label('Разблокировать')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('success')
            ->visible(fn (User $record): bool => self::canManage($record) && $record->status === UserStatus::Blocked)
            ->action(fn (User $record) => UserLifecycle::unblock($record));
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->label('В архив')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => "В архив: {$record->name}")
            ->modalDescription('Навсегда: войти не сможет, на объявлениях имя скрывается. Встречи и история остаются за ним.')
            ->visible(fn (User $record): bool => self::canManage($record) && ! $record->isArchived() && $record->role !== Role::Owner)
            ->schema(fn (User $record): array => [
                Select::make('phone_holder_id')
                    ->label('Кому передать номера')
                    ->options(UserLifecycle::phoneHolderOptions($record))
                    ->placeholder('Оставить без держателя')
                    ->visible($record->phones()->exists()),
                Select::make('team_lead_id')
                    ->label('Новый тимлид для его сотрудников')
                    ->options(UserLifecycle::teamLeadOptions($record))
                    ->required()
                    ->helperText(UserLifecycle::teamLeadOptions($record) === []
                        ? 'Другого активного тимлида этой команды нет — сначала заведите его или назначьте.'
                        : null)
                    ->visible($record->agents()->where('status', '!=', UserStatus::Archived)->exists()),
            ])
            ->action(fn (User $record, array $data) => UserLifecycle::archive(
                $record,
                isset($data['phone_holder_id']) ? (int) $data['phone_holder_id'] : null,
                isset($data['team_lead_id']) ? (int) $data['team_lead_id'] : null,
            ));
    }

    public static function restore(): Action
    {
        return Action::make('restore')
            ->label('Вернуть из архива')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->requiresConfirmation()
            ->modalDescription('Доступ вернётся. Номера не возвращаются — назначьте заново в разделе «Номера».')
            ->visible(fn (User $record): bool => self::canManage($record) && $record->isArchived())
            ->schema(fn (User $record): array => [
                Select::make('team_lead_id')
                    ->label('Новый тимлид')
                    ->options(UserLifecycle::teamLeadOptions($record))
                    ->required()
                    ->visible(UserLifecycle::needsNewTeamLeadOnRestore($record)),
            ])
            ->action(fn (User $record, array $data) => UserLifecycle::restore(
                $record,
                isset($data['team_lead_id']) ? (int) $data['team_lead_id'] : null,
            ));
    }

    private static function canManage(User $record): bool
    {
        return Auth::user()?->can('update', $record) ?? false;
    }
}
