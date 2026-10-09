<?php

namespace App\Org;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Жизненный цикл аккаунта: блокировка (временно), архив (навсегда, с передачей
 * номеров и сотрудников), возврат из архива, смена пароля. Владельца группы
 * блокировать и архивировать нельзя — иначе управлять станет некому.
 * Удаления нет: встречи и история остаются за человеком.
 */
final class UserLifecycle
{
    public const MIN_PASSWORD = 8;

    /** Временно: вход закрыт, номера, сотрудники и место в команде остаются. */
    public static function block(User $user): void
    {
        self::assertNotOwner($user);
        if (! $user->isActive()) {
            throw new InvalidArgumentException('Заблокировать можно только активного.');
        }

        $user->update(['status' => UserStatus::Blocked]);
    }

    public static function unblock(User $user): void
    {
        if ($user->status !== UserStatus::Blocked) {
            throw new InvalidArgumentException('Человек не заблокирован.');
        }

        $user->update(['status' => UserStatus::Active]);
    }

    /**
     * Навсегда: номера компании — коллеге или без держателя; работающие
     * сотрудники тимлида (активные и заблокированные) — к новому тимлиду.
     * Архивные сотрудники остаются ссылкой на прежнего: это история.
     */
    public static function archive(User $user, ?int $newPhoneHolderId, ?int $newTeamLeadId): void
    {
        self::assertNotOwner($user);
        if ($user->isArchived()) {
            throw new InvalidArgumentException('Человек уже в архиве.');
        }

        DB::transaction(function () use ($user, $newPhoneHolderId, $newTeamLeadId): void {
            $workingAgents = User::query()
                ->where('team_lead_id', $user->id)
                ->where('status', '!=', UserStatus::Archived);

            if ($workingAgents->exists()) {
                if ($newTeamLeadId === null || ! array_key_exists($newTeamLeadId, self::teamLeadOptions($user))) {
                    throw new InvalidArgumentException('Сотрудникам тимлида нужен новый тимлид той же компании и команды.');
                }
                // Мимо модели: сотрудникам меняется только тимлид той же
                // компании и команды — инварианты UserPlacement не нарушаются.
                $workingAgents->update(['team_lead_id' => $newTeamLeadId]);
            }

            if ($newPhoneHolderId !== null && ! array_key_exists($newPhoneHolderId, self::phoneHolderOptions($user))) {
                throw new InvalidArgumentException('Номера можно передать только активному сотруднику той же компании.');
            }
            Phone::query()->where('user_id', $user->id)->update(['user_id' => $newPhoneHolderId]);

            $user->update(['status' => UserStatus::Archived]);
        });
    }

    /**
     * Возврат из архива — страховка от ошибки. Номера не возвращаются.
     * Сотруднику, чей тимлид не активен, нужен новый тимлид.
     */
    public static function restore(User $user, ?int $newTeamLeadId): void
    {
        if (! $user->isArchived()) {
            throw new InvalidArgumentException('Человек не в архиве.');
        }

        if (self::needsNewTeamLeadOnRestore($user)) {
            if ($newTeamLeadId === null || ! array_key_exists($newTeamLeadId, self::teamLeadOptions($user))) {
                throw new InvalidArgumentException('Тимлид сотрудника не активен — выберите нового тимлида той же компании и команды.');
            }
            $user->team_lead_id = $newTeamLeadId;
        }

        $user->status = UserStatus::Active;
        $user->save();
    }

    public static function needsNewTeamLeadOnRestore(User $user): bool
    {
        return $user->role === Role::Agent && $user->teamLead?->isActive() !== true;
    }

    /**
     * Новый пароль. remember_token меняется, чтобы «Запомнить меня» на чужих
     * устройствах перестало работать; открытые сессии обрывает AuthenticateSession
     * панели — он сверяет хеш пароля в сессии с текущим.
     */
    public static function changePassword(User $user, string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('Пароль короче '.self::MIN_PASSWORD.' символов.');
        }

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();
    }

    public static function generatePassword(): string
    {
        return Str::password(12, symbols: false);
    }

    /** @return array<int, string> активные люди той же компании, кроме самого человека и владельца */
    public static function phoneHolderOptions(User $user): array
    {
        return User::query()
            ->where('company_id', $user->company_id)
            ->where('status', UserStatus::Active)
            ->where('role', '!=', Role::Owner)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> активные тимлиды той же компании и команды, кроме самого человека */
    public static function teamLeadOptions(User $user): array
    {
        return User::query()
            ->where('role', Role::TeamLead)
            ->where('company_id', $user->company_id)
            ->where('direction', $user->direction)
            ->where('status', UserStatus::Active)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function assertNotOwner(User $user): void
    {
        if ($user->role === Role::Owner) {
            throw new InvalidArgumentException('Владельца группы нельзя заблокировать или отправить в архив.');
        }
    }
}
