<?php

namespace App\Access;

use App\Enums\Direction;
use App\Enums\OwnershipLabel;
use App\Enums\Role;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use App\Support\PhoneNumber;
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

    /**
     * Метка принадлежности объявления по телефону с него (ТЗ 5.7).
     * Номер не наш, мусор или компания в архиве — «другая компания».
     */
    public static function ownership(User $viewer, ?string $rawPhone): Ownership
    {
        $number = PhoneNumber::tryNormalize($rawPhone);
        if ($number === null) {
            return Ownership::other();
        }

        $phone = Phone::query()->with(['company', 'holder'])->where('number', $number)->first();
        if ($phone === null || $phone->company->isArchived()) {
            return Ownership::other();
        }

        $label = self::labelFor($viewer, $phone->company_id);
        if ($label === OwnershipLabel::OtherCompany) {
            return Ownership::other();
        }

        // Уволенный или не назначенный держатель — имени нет; контакт — сам
        // номер: SIM осталась в компании, по нему ответят.
        $holder = $phone->holder;
        $contactName = $holder !== null && $holder->is_active ? $holder->name : null;

        return new Ownership($label, $phone->company->name, $contactName, $phone->number);
    }

    private static function labelFor(User $viewer, int $companyId): OwnershipLabel
    {
        return match ($viewer->role) {
            Role::Owner, Role::CompanyOwner => OwnershipLabel::GroupCompany,
            Role::TeamLead, Role::Agent => match (true) {
                $companyId === $viewer->company_id => OwnershipLabel::OurEmployee,
                CompanyExchange::enabledBetween((int) $viewer->company_id, $companyId) => OwnershipLabel::LinkedCompany,
                default => OwnershipLabel::OtherCompany,
            },
        };
    }
}
