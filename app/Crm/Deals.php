<?php

namespace App\Crm;

use App\Access\OrgAccess;
use App\Crm\Exceptions\CrmForbidden;
use App\Enums\DealStatus;
use App\Models\Client;
use App\Models\Deal;
use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Сделки: открыть, двигать по этапам, закрыть. Закрытую сделку менять нельзя. */
class Deals
{
    public function __construct(private readonly Pins $pins) {}

    public function open(ListingPin $pin, Client $client, User $by): Deal
    {
        if (! $pin->isActive() || ! $this->pins->canManage($pin, $by)) {
            throw new CrmForbidden('Cannot open a deal on this pin.');
        }

        try {
            return DB::transaction(function () use ($pin, $client, $by): Deal {
                $this->lockActivePin($pin);
                if ($pin->deals()->where('client_id', $client->id)->exists()) {
                    throw new CrmForbidden('This client already has a deal on this pin.');
                }

                $stage = DealStages::first()->key;
                $deal = Deal::query()->create([
                    'listing_pin_id' => $pin->id,
                    'client_id' => $client->id,
                    'user_id' => $pin->user_id,
                    'stage' => $stage,
                    'status' => DealStatus::Open,
                ]);
                $this->logStage($deal, null, $stage, $by);
                Journal::record(Journal::DEAL_OPENED, $deal, $this->props($deal) + ['stage' => $stage], $by);
                $this->pins->touch($pin);

                return $deal;
            });
        } catch (UniqueConstraintViolationException) {
            throw new CrmForbidden('This client already has a deal on this pin.');
        }
    }

    public function moveStage(Deal $deal, string $key, User $by): void
    {
        $stage = DealStages::find($key);
        if ($stage === null || $stage->hidden || $stage->kind !== 'normal') {
            throw new CrmForbidden('Stage is not available.');
        }

        DB::transaction(function () use ($deal, $key, $by): void {
            $this->lockOpen($deal, $by);
            $from = $deal->stage;
            $deal->update(['stage' => $key]);
            $this->logStage($deal, $from, $key, $by);
            Journal::record(Journal::DEAL_STAGE_CHANGED, $deal, $this->props($deal) + ['from' => $from, 'to' => $key], $by);
            $this->pins->touch($deal->pin);
        });
    }

    public function win(Deal $deal, string $amount, string $currency, User $by): void
    {
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new CrmForbidden('Amount must be a positive number.');
        }
        if (! in_array($currency, config('crm.currencies'), true)) {
            throw new CrmForbidden('Unknown currency.');
        }
        $stage = $this->terminalStage('won');

        DB::transaction(function () use ($deal, $amount, $currency, $stage, $by): void {
            $this->lockOpen($deal, $by);
            $from = $deal->stage;
            $deal->update([
                'stage' => $stage,
                'status' => DealStatus::Won,
                'amount' => $amount,
                'currency' => $currency,
                'closed_at' => now(),
            ]);
            $this->logStage($deal, $from, $stage, $by);
            Journal::record(Journal::DEAL_WON, $deal, $this->props($deal) + ['amount' => $amount, 'currency' => $currency], $by);
            $this->pins->touch($deal->pin);
            // Остальные открытые сделки пина закроет слушатель PinClosed('won').
            $this->pins->close($deal->pin, 'won', Journal::PIN_WON, $by);
        });
    }

    public function lose(Deal $deal, string $reason, User $by): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new CrmForbidden('Reason is required.');
        }
        $stage = $this->terminalStage('lost');

        DB::transaction(function () use ($deal, $reason, $stage, $by): void {
            $this->lockOpen($deal, $by);
            $from = $deal->stage;
            $deal->update([
                'stage' => $stage,
                'status' => DealStatus::Lost,
                'lost_reason' => $reason,
                'closed_at' => now(),
            ]);
            $this->logStage($deal, $from, $stage, $by);
            Journal::record(Journal::DEAL_LOST, $deal, $this->props($deal), $by);
            $this->pins->touch($deal->pin);
        });
    }

    /** Агент сделки либо тот, кто его видит по ступени (как с закреплениями — и для ушедшего агента). */
    public function canManage(Deal $deal, User $user): bool
    {
        return $deal->user_id === $user->id || OrgAccess::canSee($user, $deal->agent);
    }

    /** Ключ видимого этапа нужного вида. */
    private function terminalStage(string $kind): string
    {
        foreach (DealStages::visible() as $stage) {
            if ($stage->kind === $kind) {
                return $stage->key;
            }
        }

        throw new CrmForbidden("No visible stage of kind {$kind}.");
    }

    /** Перечитать сделку под блокировкой: за это время её могли закрыть закрытием пина. */
    private function lockOpen(Deal $deal, User $by): void
    {
        $fresh = Deal::query()->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();
        if (! $fresh->isOpen() || ! $this->canManage($fresh, $by)) {
            throw new CrmForbidden('Cannot change this deal.');
        }
        $deal->setRawAttributes($fresh->getAttributes(), true);
    }

    private function lockActivePin(ListingPin $pin): void
    {
        $fresh = ListingPin::query()->whereKey($pin->getKey())->lockForUpdate()->firstOrFail();
        if (! $fresh->isActive()) {
            throw new CrmForbidden('Pin is already closed.');
        }
        $pin->setRawAttributes($fresh->getAttributes(), true);
    }

    private function logStage(Deal $deal, ?string $from, string $to, User $by): void
    {
        $deal->stageChanges()->create(['from_stage' => $from, 'to_stage' => $to, 'changed_by' => $by->id, 'changed_at' => now()]);
    }

    /** @return array<string, int> */
    private function props(Deal $deal): array
    {
        return ['deal_id' => $deal->id, 'client_id' => $deal->client_id, 'listing_id' => $deal->pin->listing_id];
    }
}
