<?php

namespace App\Access;

use App\Enums\Direction;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Единственное место правил видимости оргструктуры (docs/GSHome, спецификация
 * docs/superpowers/specs/2026-10-09-orgstructure-design.md). Политики, ресурсы
 * и будущие экраны объявлений только спрашивают его.
 */
final class OrgAccess
{
    /**
     * Направления объектов, которые видит человек. Передаются в запрос к API
     * Real Estate — чужое направление не приходит вовсе.
     *
     * @return array<int, Direction>
     */
    public static function directions(User $user): array
    {
        if (! $user->role->hasTeam() || $user->both_directions) {
            return Direction::cases();
        }

        return [$user->direction];
    }

    /**
     * Люди, которых человек видит на экранах команды и контроля.
     * «Оба направления» список людей не расширяет (ТЗ 5.6).
     *
     * @return Builder<User>
     */
    public static function visibleUsers(User $viewer): Builder
    {
        $query = User::query();

        return match ($viewer->role) {
            Role::Owner => $query,
            Role::CompanyOwner => $query->where('company_id', $viewer->company_id),
            Role::TeamLead => $query->where(fn (Builder $q) => $q
                ->whereKey($viewer->getKey())
                ->orWhere('team_lead_id', $viewer->getKey())),
            Role::Agent => $query->whereKey($viewer->getKey()),
        };
    }
}
