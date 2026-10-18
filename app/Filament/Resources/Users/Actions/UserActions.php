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
            ->label(__('gshome.user.change_password'))
            ->icon(Heroicon::OutlinedKey)
            ->modalHeading(fn (User $record): string => __('gshome.user.change_password_heading', ['name' => $record->name]))
            ->modalDescription(__('gshome.user.change_password_description'))
            ->modalSubmitActionLabel(__('gshome.user.change'))
            ->visible(fn (User $record): bool => self::canManage($record) && ! $record->isArchived())
            ->schema([
                TextInput::make('password')
                    ->label(__('gshome.user.new_password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(UserLifecycle::MIN_PASSWORD)
                    ->confirmed()
                    ->suffixAction(
                        Action::make('generate')
                            ->label(__('gshome.user.generate'))
                            ->icon(Heroicon::OutlinedSparkles)
                            ->action(function (Set $set): void {
                                $password = UserLifecycle::generatePassword();
                                $set('password', $password);
                                $set('password_confirmation', $password);
                            }),
                    ),
                TextInput::make('password_confirmation')
                    ->label(__('gshome.user.password_confirmation'))
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
                    ->title(__('gshome.user.password_changed'))
                    ->body(__('gshome.user.password_body', ['email' => $record->email, 'password' => $data['password']]))
                    ->send();
            });
    }

    public static function block(): Action
    {
        return Action::make('block')
            ->label(__('gshome.user.block'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('gshome.user.block_heading', ['name' => $record->name]))
            ->modalDescription(__('gshome.user.block_description'))
            ->visible(fn (User $record): bool => self::canManage($record) && $record->isActive() && $record->role !== Role::Owner)
            ->action(fn (User $record) => UserLifecycle::block($record));
    }

    public static function unblock(): Action
    {
        return Action::make('unblock')
            ->label(__('gshome.user.unblock'))
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('success')
            ->visible(fn (User $record): bool => self::canManage($record) && $record->status === UserStatus::Blocked)
            ->action(fn (User $record) => UserLifecycle::unblock($record));
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->label(__('gshome.user.archive'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => __('gshome.user.archive_heading', ['name' => $record->name]))
            ->modalDescription(__('gshome.user.archive_description'))
            ->visible(fn (User $record): bool => self::canManage($record) && ! $record->isArchived() && $record->role !== Role::Owner)
            ->schema(fn (User $record): array => [
                Select::make('phone_holder_id')
                    ->label(__('gshome.user.phone_holder'))
                    ->options(UserLifecycle::phoneHolderOptions($record))
                    ->placeholder(__('gshome.user.no_holder'))
                    ->visible($record->phones()->exists()),
                Select::make('team_lead_id')
                    ->label(__('gshome.user.new_lead_for_agents'))
                    ->options(UserLifecycle::teamLeadOptions($record))
                    ->required()
                    ->helperText(UserLifecycle::teamLeadOptions($record) === []
                        ? __('gshome.user.no_other_lead')
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
            ->label(__('gshome.user.restore'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->requiresConfirmation()
            ->modalDescription(__('gshome.user.restore_description'))
            ->visible(fn (User $record): bool => self::canManage($record) && $record->isArchived())
            ->schema(fn (User $record): array => [
                Select::make('team_lead_id')
                    ->label(__('gshome.user.new_lead'))
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
