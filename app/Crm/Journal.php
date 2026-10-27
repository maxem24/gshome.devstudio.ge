<?php

namespace App\Crm;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

/**
 * Единственная точка записи действий человека в журнал.
 * В свойства не кладём имя и телефон клиента: лента их не показывает.
 */
final class Journal
{
    public const CLIENT_CREATED = 'client.created';

    public const CLIENT_PHONE_REASSIGNED = 'client.phone_reassigned';

    /** Выбор «Это тот же человек» в окне конфликта номера; не событие ленты. */
    public const CLIENT_SAME_PERSON_CONFIRMED = 'client.same_person_confirmed';

    public const PIN_TAKEN = 'pin.taken';

    public const PIN_TRANSFERRED = 'pin.transferred';

    public const PIN_UNAVAILABLE = 'pin.unavailable';

    public const PIN_RELEASED = 'pin.released';

    public const PIN_WON = 'pin.won';

    public const DEAL_OPENED = 'deal.opened';

    public const DEAL_STAGE_CHANGED = 'deal.stage_changed';

    public const DEAL_WON = 'deal.won';

    public const DEAL_LOST = 'deal.lost';

    public const VIEWING_SCHEDULED = 'viewing.scheduled';

    public const VIEWING_RESCHEDULED = 'viewing.rescheduled';

    public const VIEWING_OUTCOME = 'viewing.outcome';

    public const TASK_CREATED = 'task.created';

    public const TASK_STATUS_CHANGED = 'task.status_changed';

    public const ARCHIVED = 'archived';

    public const RESTORED = 'restored';

    public const LISTING_VIEWED = 'listing.viewed';

    /** Нажата «Собрать полную информацию»; не событие ленты. */
    public const LISTING_REFRESH_STARTED = 'listing.refresh_started';

    /** Поиск по сайтам; не событие ленты. */
    public const SEARCH_STARTED = 'search.started';

    public const SEARCH_FINISHED = 'search.finished';

    /** Настройка, не событие ленты. */
    public const DEAL_STAGES_SAVED = 'settings.deal_stages_saved';

    /** События ленты: всё про закрепления, сделки и показы. */
    public const FEED_EVENTS = [
        self::PIN_TAKEN,
        self::PIN_TRANSFERRED,
        self::PIN_UNAVAILABLE,
        self::PIN_RELEASED,
        self::PIN_WON,
        self::DEAL_OPENED,
        self::DEAL_STAGE_CHANGED,
        self::DEAL_WON,
        self::DEAL_LOST,
        self::VIEWING_SCHEDULED,
        self::VIEWING_RESCHEDULED,
        self::VIEWING_OUTCOME,
    ];

    /**
     * @param  array<string, mixed>  $properties
     * @param  ?CarbonInterface  $at  время события — для демо-данных в прошлом
     */
    public static function record(
        string $event,
        ?Model $subject,
        array $properties = [],
        ?User $causer = null,
        ?CarbonInterface $at = null,
    ): Activity {
        $causer ??= Auth::user();

        $logger = activity()->event($event)->withProperties($properties);
        if ($subject !== null) {
            $logger->performedOn($subject);
        }
        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        /** @var Activity $activity */
        $activity = $logger->log($event);

        if ($at !== null) {
            $time = Carbon::instance($at);
            $activity->created_at = $time;
            $activity->updated_at = $time;
            $activity->save();
        }

        return $activity;
    }
}
