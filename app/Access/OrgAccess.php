<?php

namespace App\Access;

use App\Enums\Direction;
use App\Enums\OwnershipLabel;
use App\Enums\Role;
use App\Models\Company;
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
     * Может ли человек действовать за другого: ставить задачу, передавать объект, вести его
     * сделку. Ступени — те же, что у visibleUsers; за архивного и заблокированного — нельзя.
     */
    public static function canActFor(User $actor, User $target): bool
    {
        return $target->isActive() && self::canSee($actor, $target);
    }

    /**
     * Видит ли человек другого по ступени — без проверки статуса. Нужна, чтобы руководитель
     * мог разобрать записи ушедшего в архив или заблокированного сотрудника.
     */
    public static function canSee(User $viewer, User $target): bool
    {
        return self::visibleUsers($viewer)->whereKey($target->getKey())->exists();
    }

    /**
     * Чьей ступенью мерить получателя при передаче записи. Сам агент никого, кроме себя, не
     * видит, поэтому держатель-агент передаёт в пределах ступени своего тимлида — коллегам
     * своей группы. Остальные передают тем, за кого действуют сами.
     */
    public static function handOverScope(User $by, int $holderId): User
    {
        return $by->id === $holderId && $by->teamLead !== null ? $by->teamLead : $by;
    }

    /**
     * Метка принадлежности объявления по телефону с него (ТЗ 5.7).
     * Номер не наш, мусор или компания в архиве — «другая компания».
     */
    public static function ownership(User $viewer, ?string $rawPhone): Ownership
    {
        $number = PhoneNumber::tryNormalize($rawPhone);

        return $number === null ? Ownership::other() : self::ownershipMany($viewer, [$number])[$number];
    }

    /**
     * Метки для всех номеров страницы сразу: один запрос к phones, один к парам обмена.
     *
     * @param  array<int, string|null>  $phonesE164  номера уже в E.164 (из API)
     * @return array<string, Ownership> номер ⇒ метка
     */
    public static function ownershipMany(User $viewer, array $phonesE164): array
    {
        $numbers = collect($phonesE164)->filter(fn ($n): bool => is_string($n) && $n !== '')->unique()->values();
        if ($numbers->isEmpty()) {
            return [];
        }

        $phones = Phone::query()->with(['company', 'holder'])->whereIn('number', $numbers)->get()->keyBy('number');
        $partners = $viewer->role->hasTeam() ? CompanyExchange::enabledPartnersOf((int) $viewer->company_id) : [];

        return $numbers->mapWithKeys(fn (string $n): array => [$n => self::ownershipFor($viewer, $phones->get($n), $partners)])->all();
    }

    /** @param  list<int>  $partners */
    private static function ownershipFor(User $viewer, ?Phone $phone, array $partners): Ownership
    {
        if ($phone === null || $phone->company->isArchived()) {
            return Ownership::other();
        }

        $label = match ($viewer->role) {
            Role::Owner, Role::CompanyOwner => OwnershipLabel::GroupCompany,
            Role::TeamLead, Role::Agent => match (true) {
                $phone->company_id === $viewer->company_id => OwnershipLabel::OurEmployee,
                in_array($phone->company_id, $partners, true) => OwnershipLabel::LinkedCompany,
                default => OwnershipLabel::OtherCompany,
            },
        };
        if ($label === OwnershipLabel::OtherCompany) {
            return Ownership::other();
        }

        // Архивный или не назначенный держатель — имени нет; контакт — сам номер:
        // SIM осталась в компании. Заблокированный временно — имя видно.
        $holder = $phone->holder;
        $contactName = $holder !== null && ! $holder->isArchived() ? $holder->name : null;

        return new Ownership($label, $phone->company->name, $contactName, $phone->number);
    }

    /** @return list<int> компании группы, чьих людей смотрящий видит на объявлениях по имени */
    public static function identifiableCompanyIds(User $viewer): array
    {
        $active = Company::query()->whereNull('archived_at');

        if ($viewer->role->hasTeam()) {
            $active->whereIn('id', [(int) $viewer->company_id, ...CompanyExchange::enabledPartnersOf((int) $viewer->company_id)]);
        }

        return $active->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return list<string> номера этих компаний — для фильтров «наши» / «чужие» */
    public static function identifiablePhones(User $viewer): array
    {
        return Phone::query()->whereIn('company_id', self::identifiableCompanyIds($viewer))->pluck('number')->all();
    }

    /**
     * Номера «нашего агентства»: компания смотрящего, без связанных по обмену.
     * У владельца группы компании нет — номера всех действующих компаний группы.
     *
     * @return list<string>
     */
    public static function agencyPhones(User $viewer): array
    {
        $companies = $viewer->company_id !== null
            ? [(int) $viewer->company_id]
            : Company::query()->whereNull('archived_at')->pluck('id')->all();

        return $companies === []
            ? []
            : Phone::query()->whereIn('company_id', $companies)->pluck('number')->all();
    }

    /** @return list<string> номера, держатель которых — сам смотрящий */
    public static function holderPhones(User $viewer): array
    {
        return Phone::query()->where('user_id', $viewer->id)->pluck('number')->all();
    }
}
