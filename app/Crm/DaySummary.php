<?php

namespace App\Crm;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\ListingPin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Сводка дня для инфопанели: приветствие по времени в Тбилиси, что в работе и что сегодня. */
final class DaySummary
{
    public const TODAY_LIMIT = 5;

    public const PHRASES = 5;

    public function __construct(private readonly Agenda $agenda) {}

    /**
     * $now задаёт только приветствие и границу «сегодня»; пометку «просрочено» Agenda ставит по настоящему времени.
     *
     * @return array{greeting: 'morning'|'day'|'evening', phrase: int, pins: int, clients: int, tasks: int, today: list<AgendaItem>, today_more: int}
     */
    public function for(User $user, ?CarbonInterface $now = null): array
    {
        $local = CarbonImmutable::instance($now ?? now())->setTimezone((string) config('crm.timezone'));
        $hour = (int) $local->format('G');

        $agenda = $this->agenda->for($user);
        $endOfDay = $local->endOfDay();
        $today = $agenda->filter(fn (AgendaItem $i): bool => $i->at !== null && $i->at->lessThanOrEqualTo($endOfDay))->values();

        return [
            'greeting' => match (true) {
                $hour >= 5 && $hour < 12 => 'morning',
                $hour >= 12 && $hour < 18 => 'day',
                default => 'evening',
            },
            // Одна фраза на весь день, назавтра другая.
            'phrase' => $local->dayOfYear % self::PHRASES,
            'pins' => ListingPin::query()->where('user_id', $user->id)->where('status', ListingPin::ACTIVE)->count(),
            'clients' => Deal::query()->where('user_id', $user->id)->where('status', DealStatus::Open->value)->distinct()->count('client_id'),
            'tasks' => $agenda->count(),
            'today' => $today->take(self::TODAY_LIMIT)->all(),
            'today_more' => max(0, $today->count() - self::TODAY_LIMIT),
        ];
    }
}
