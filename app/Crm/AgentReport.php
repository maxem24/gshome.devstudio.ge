<?php

namespace App\Crm;

use App\Access\OrgAccess;
use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\ListingPin;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Отчёт руководителя: по одному запросу на показатель сразу для всех видимых сотрудников. */
final class AgentReport
{
    /** @return list<AgentReportRow> */
    public function rows(User $viewer, CarbonInterface $from, CarbonInterface $to): array
    {
        $users = OrgAccess::visibleUsers($viewer)
            ->whereIn('role', [Role::Agent->value, Role::TeamLead->value])
            ->orderBy('name')->get();
        if ($users->isEmpty()) {
            return [];
        }
        $ids = $users->modelKeys();
        $now = now();

        $pinsActive = ListingPin::query()->whereIn('user_id', $ids)->where('status', ListingPin::ACTIVE)
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        $stale = ListingPin::query()->whereIn('user_id', $ids)->where('status', ListingPin::ACTIVE)
            ->where('last_activity_at', '<', $now->copy()->subDays((int) config('crm.stale_days')))
            ->orderBy('last_activity_at')->get(['id', 'user_id'])->groupBy('user_id');

        $opened = DB::table('deals')->whereNull('deleted_at')->whereIn('user_id', $ids)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        $viewings = DB::table('viewings')->join('deals', 'deals.id', '=', 'viewings.deal_id')
            ->whereNull('viewings.deleted_at')->whereNull('deals.deleted_at')
            ->whereIn('deals.user_id', $ids)->whereNotNull('viewings.outcome')
            ->whereBetween('viewings.outcome_at', [$from, $to])
            ->selectRaw('deals.user_id as user_id, count(*) as n')->groupBy('deals.user_id')->pluck('n', 'user_id');

        // won/lost и деньги: unavailable в проигрыши не входит
        $closed = DB::table('deals')->whereNull('deleted_at')->whereIn('user_id', $ids)
            ->whereIn('status', [DealStatus::Won->value, DealStatus::Lost->value])
            ->whereBetween('closed_at', [$from, $to])
            ->selectRaw('user_id, status, currency, count(*) as n, sum(amount) as total')
            ->groupBy('user_id', 'status', 'currency')->get()->groupBy('user_id');

        $normalKeys = array_values(array_map(
            fn (DealStage $s): string => $s->key,
            array_filter(DealStages::all(), fn (DealStage $s): bool => $s->kind === 'normal'),
        ));

        $stages = DB::table(DB::raw('(select c.deal_id, c.to_stage, c.changed_at,
                lead(c.changed_at) over (partition by c.deal_id order by c.changed_at, c.id) as next_at
                from deal_stage_changes c) s'))
            ->join('deals', 'deals.id', '=', 's.deal_id')
            ->whereNull('deals.deleted_at')->whereIn('deals.user_id', $ids)
            ->whereBetween('s.changed_at', [$from, $to])
            // Итоговые этапы (won/lost) в среднее не входят.
            ->whereIn('s.to_stage', $normalKeys)
            // Последний этап закрытой сделки кончается её закрытием, а не «сейчас».
            ->selectRaw('deals.user_id as user_id, s.to_stage as stage,
                avg(extract(epoch from (coalesce(s.next_at, deals.closed_at, ?::timestamptz) - s.changed_at))) as secs', [$now])
            ->groupBy('deals.user_id', 's.to_stage')->get()->groupBy('user_id');

        return $users->map(function (User $u) use ($pinsActive, $stale, $opened, $viewings, $closed, $stages): AgentReportRow {
            $mine = $closed->get($u->id, collect());
            $earnings = [];
            foreach ($mine->where('status', DealStatus::Won->value) as $r) {
                if ($r->currency !== null && $r->total !== null) {
                    $earnings[(string) $r->currency] = (string) $r->total;
                }
            }
            $staleIds = $stale->get($u->id, collect())->pluck('id')->map(fn ($id): int => (int) $id)->all();

            return new AgentReportRow(
                user: $u,
                pinsActive: (int) ($pinsActive[$u->id] ?? 0),
                dealsOpened: (int) ($opened[$u->id] ?? 0),
                viewingsHeld: (int) ($viewings[$u->id] ?? 0),
                dealsWon: (int) $mine->where('status', DealStatus::Won->value)->sum('n'),
                dealsLost: (int) $mine->where('status', DealStatus::Lost->value)->sum('n'),
                earnings: $earnings,
                avgStageSeconds: $stages->get($u->id, collect())
                    ->mapWithKeys(fn ($r): array => [(string) $r->stage => (int) round((float) $r->secs)])->all(),
                stalePins: count($staleIds),
                stalePinIds: $staleIds,
            );
        })->all();
    }
}
