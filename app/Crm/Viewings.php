<?php

namespace App\Crm;

use App\Crm\Exceptions\CrmForbidden;
use App\Enums\ViewingOutcome;
use App\Models\Deal;
use App\Models\User;
use App\Models\Viewing;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Показы по сделке. Права — те же, что у сделки (Deals::canManage). */
class Viewings
{
    public function __construct(private readonly Deals $deals, private readonly Pins $pins) {}

    public function schedule(Deal $deal, CarbonInterface $at, ?string $notes, User $by): Viewing
    {
        $this->assertManage($deal, $by, requireOpen: true);

        return DB::transaction(function () use ($deal, $at, $notes, $by): Viewing {
            $viewing = $deal->viewings()->create(['scheduled_at' => $at, 'notes' => $notes]);
            Journal::record(Journal::VIEWING_SCHEDULED, $viewing, $this->props($viewing) + ['new_at' => $at->toIso8601String()], $by);
            $this->pins->touch($deal->pin);

            return $viewing;
        });
    }

    public function reschedule(Viewing $viewing, CarbonInterface $at, User $by): void
    {
        $deal = $viewing->deal;
        $this->assertManage($deal, $by, requireOpen: true);

        DB::transaction(function () use ($viewing, $deal, $at, $by): void {
            $old = $viewing->scheduled_at;
            $viewing->update(['scheduled_at' => $at]);
            Journal::record(Journal::VIEWING_RESCHEDULED, $viewing, $this->props($viewing) + [
                'old_at' => $old->toIso8601String(),
                'new_at' => $at->toIso8601String(),
            ], $by);
            $this->pins->touch($deal->pin);
        });
    }

    public function recordOutcome(Viewing $viewing, ViewingOutcome $outcome, User $by): void
    {
        $deal = $viewing->deal;
        $this->assertManage($deal, $by, requireOpen: true);

        DB::transaction(function () use ($viewing, $deal, $outcome, $by): void {
            $viewing->update(['outcome' => $outcome, 'outcome_at' => now()]);
            Journal::record(Journal::VIEWING_OUTCOME, $viewing, $this->props($viewing) + [
                'outcome' => $outcome->value,
                'at' => $viewing->outcome_at->toIso8601String(),
            ], $by);
            $this->pins->touch($deal->pin);
        });
    }

    private function assertManage(Deal $deal, User $by, bool $requireOpen): void
    {
        if (! $this->deals->canManage($deal, $by) || ($requireOpen && ! $deal->isOpen())) {
            throw new CrmForbidden('Cannot change viewings of this deal.');
        }
    }

    /** @return array<string, int> */
    private function props(Viewing $viewing): array
    {
        return ['viewing_id' => $viewing->id, 'deal_id' => $viewing->deal_id, 'listing_id' => $viewing->deal->pin->listing_id];
    }
}
