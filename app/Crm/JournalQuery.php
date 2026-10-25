<?php

namespace App\Crm;

use App\Access\OrgAccess;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;

/** Чтение журнала: кто какие записи видит. Пишет в журнал только Journal. */
final class JournalQuery
{
    /**
     * Записи людей, которых видит смотрящий. Агенту журнал не положен — пусто.
     *
     * @return Builder<Activity>
     */
    public static function visibleTo(User $viewer): Builder
    {
        /** @var Builder<Activity> $query */
        $query = Activity::query();

        return self::restrict($query, $viewer);
    }

    /**
     * Сузить чужой запрос (вкладка журнала на карточке) до видимого смотрящему.
     *
     * @template T of Builder|Relation
     *
     * @param  T  $query
     * @return T
     */
    public static function restrict(Builder|Relation $query, User $viewer): Builder|Relation
    {
        if ($viewer->role === Role::Agent) {
            return $query->whereRaw('false');
        }

        return $query
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('causer_id', OrgAccess::visibleUsers($viewer)->select('users.id'));
    }

    /**
     * Лента главной: только события из FEED_EVENTS, люди своей компании (владелец группы — все).
     * Клиентских данных здесь нет: строку собирает виджет из события, имени агента и объекта.
     *
     * @return Builder<Activity>
     */
    public static function feed(User $viewer, int $limit = 30): Builder
    {
        $people = User::query()->select('users.id');
        if ($viewer->role !== Role::Owner) {
            $people->where('company_id', $viewer->company_id);
        }

        /** @var Builder<Activity> $query */
        $query = Activity::query();

        return $query
            ->whereIn('event', Journal::FEED_EVENTS)
            ->where('causer_type', (new User)->getMorphClass())
            ->whereIn('causer_id', $people)
            ->with('causer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit);
    }

    /** Фильтр по объекту: и просмотры, и закрепления с ним хранят listing_id в свойствах. */
    public static function forListing(Builder $query, int $listingId): Builder
    {
        return $query->where('properties->listing_id', (string) $listingId);
    }
}
