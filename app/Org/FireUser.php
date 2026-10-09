<?php

namespace App\Org;

use App\Enums\Role;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Увольнение: человек выключается, не удаляется (встречи и история за ним).
 * Номера компании передаются коллеге или остаются без держателя; сотрудники
 * тимлида обязаны перейти к другому тимлиду — у сотрудника тимлид обязателен.
 */
final class FireUser
{
    public static function handle(User $user, ?int $newPhoneHolderId, ?int $newTeamLeadId): void
    {
        DB::transaction(function () use ($user, $newPhoneHolderId, $newTeamLeadId): void {
            // Новый тимлид нужен только активным сотрудникам; уволенные остаются
            // ссылкой на прежнего — история, а не рабочая структура.
            if ($user->agents()->where('is_active', true)->exists()) {
                $newLead = $newTeamLeadId !== null ? User::query()->find($newTeamLeadId) : null;
                if ($newLead === null || ! array_key_exists($newLead->id, self::teamLeadOptions($user))) {
                    throw new InvalidArgumentException('Сотрудникам тимлида нужен новый тимлид той же компании и команды.');
                }
                // Мимо модели: каждому сотруднику меняется только тимлид той же
                // компании и команды — инварианты UserPlacement не нарушаются.
                User::query()->where('team_lead_id', $user->id)->where('is_active', true)->update(['team_lead_id' => $newLead->id]);
            }

            if ($newPhoneHolderId !== null && ! array_key_exists($newPhoneHolderId, self::phoneHolderOptions($user))) {
                throw new InvalidArgumentException('Номера можно передать только активному сотруднику той же компании.');
            }
            Phone::query()->where('user_id', $user->id)->update(['user_id' => $newPhoneHolderId]);

            $user->update(['is_active' => false]);
        });
    }

    /** @return array<int, string> активные люди той же компании, кроме увольняемого и владельца */
    public static function phoneHolderOptions(User $user): array
    {
        return User::query()
            ->where('company_id', $user->company_id)
            ->where('is_active', true)
            ->where('role', '!=', Role::Owner)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> активные тимлиды той же компании и команды, кроме увольняемого */
    public static function teamLeadOptions(User $user): array
    {
        return User::query()
            ->where('role', Role::TeamLead)
            ->where('company_id', $user->company_id)
            ->where('direction', $user->direction)
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
