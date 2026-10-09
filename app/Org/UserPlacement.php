<?php

namespace App\Org;

use App\Enums\Role;
use App\Models\Phone;
use App\Models\User;
use InvalidArgumentException;

/**
 * Приводит место человека в структуре к его роли перед сохранением.
 * CHECK-ограничения в базе — последний рубеж; здесь — понятная ошибка и
 * очистка полей, которые новой роли не положены (смена роли в форме).
 */
final class UserPlacement
{
    public static function apply(User $user): void
    {
        $role = $user->role;
        if ($role === null) {
            return; // NOT NULL в базе даст свою ошибку
        }

        // Сотрудники тимлида ссылаются на него: если он уходит из своей
        // компании, команды или роли, они повисли бы на чужом человеке.
        if ($user->exists
            && ($role !== Role::TeamLead || $user->isDirty(['company_id', 'direction']))
            && $user->agents()->exists()) {
            throw new InvalidArgumentException('У тимлида есть сотрудники: сначала переведите их к другому тимлиду.');
        }

        if ($role === Role::Owner) {
            $user->company_id = null;
        }

        if (! $role->hasTeam()) {
            $user->direction = null;
            $user->both_directions = false;
        }

        if ($role !== Role::Agent) {
            $user->team_lead_id = null;
        }

        // Номер принадлежит компании: при переводе человека в другую компанию
        // номера прежней остаются за ней, без держателя.
        if ($user->exists && $user->isDirty('company_id')) {
            Phone::query()->where('user_id', $user->getKey())->update(['user_id' => null]);
        }

        if ($role === Role::Agent) {
            self::assertTeamLead($user);
        }
    }

    private static function assertTeamLead(User $user): void
    {
        $lead = $user->team_lead_id !== null ? User::query()->find($user->team_lead_id) : null;

        if ($lead === null
            || $lead->role !== Role::TeamLead
            || $lead->company_id !== $user->company_id
            || $lead->direction !== $user->direction) {
            throw new InvalidArgumentException('Тимлид сотрудника должен быть тимлидом той же компании и команды.');
        }
    }
}
