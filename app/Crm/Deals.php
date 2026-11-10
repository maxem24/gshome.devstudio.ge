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
                $this->lockActivePin($pin, $by);
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
        if (! DealIntake::isMoney($amount)) {
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

    /**
     * Анкета клиента и цена: оба необязательны, но цена и валюта — парой.
     *
     * @param  array<string, mixed>  $intake
     */
    public function updateIntake(Deal $deal, array $intake, ?string $price, ?string $priceCurrency, User $by): void
    {
        $price = $price === null ? null : trim($price);
        $priceCurrency = $priceCurrency === null ? null : trim($priceCurrency);
        $price = $price === '' ? null : $price;
        $priceCurrency = $priceCurrency === '' ? null : $priceCurrency;
        if (($price === null) !== ($priceCurrency === null)) {
            throw new CrmForbidden('Price and currency go together.');
        }
        if ($price !== null) {
            if (! DealIntake::isMoney($price)) {
                throw new CrmForbidden('Price must be a positive number.');
            }
            if (! in_array($priceCurrency, config('crm.currencies'), true)) {
                throw new CrmForbidden('Unknown currency.');
            }
            $price = number_format((float) $price, 2, '.', '');
        }

        DB::transaction(function () use ($deal, $intake, $price, $priceCurrency, $by): void {
            $this->lockOpen($deal, $by);
            $new = DealIntake::clean($deal->pin->direction, $intake);
            /** @var array<string, mixed> $old */
            $old = $deal->intake ?? [];

            $changed = [];
            foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
                if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                    $changed[$key] = ['from' => $old[$key] ?? null, 'to' => $new[$key] ?? null];
                }
            }
            if ($deal->price !== $price) {
                $changed['price'] = ['from' => $deal->price, 'to' => $price];
            }
            if ($deal->price_currency !== $priceCurrency) {
                $changed['price_currency'] = ['from' => $deal->price_currency, 'to' => $priceCurrency];
            }
            if ($changed === []) {
                return;
            }

            $deal->update(['intake' => $new, 'price' => $price, 'price_currency' => $priceCurrency]);
            Journal::record(Journal::DEAL_INTAKE_CHANGED, $deal, $this->props($deal) + ['changed' => $changed], $by);
            $this->pins->touch($deal->pin);
        });
    }

    /** Партнёр по сделке: зарегистрированный агент круга компаний агента сделки; null снимает. */
    public function setPartner(Deal $deal, ?User $partner, User $by): void
    {
        DB::transaction(function () use ($deal, $partner, $by): void {
            $this->lockOpen($deal, $by);
            $previous = $deal->partner_user_id;
            if ($previous === $partner?->id) {
                return;
            }

            if ($partner === null) {
                $this->removePartner($deal, 'manual', $by);

                return;
            }
            if (! OrgAccess::canPartner($deal->agent, $partner)) {
                throw new CrmForbidden('This user cannot be a partner.');
            }

            $deal->update(['partner_user_id' => $partner->id]);
            Journal::record(Journal::DEAL_PARTNER_SET, $deal, $this->props($deal) + ['partner_id' => $partner->id, 'previous_id' => $previous], $by);
            $this->pins->touch($deal->pin);
        });
    }

    /** Снять партнёра под уже взятой блокировкой; причина: manual | became_holder. */
    public function removePartner(Deal $deal, string $reason, User $by): void
    {
        $previous = $deal->partner_user_id;
        if ($previous === null) {
            return;
        }

        $deal->update(['partner_user_id' => null]);
        Journal::record(Journal::DEAL_PARTNER_REMOVED, $deal, $this->props($deal) + ['partner_id' => $previous, 'reason' => $reason], $by);
        $this->pins->touch($deal->pin);
    }

    public function isPartner(Deal $deal, User $user): bool
    {
        return $deal->partner_user_id !== null && $deal->partner_user_id === $user->id;
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
    public function lockOpen(Deal $deal, User $by): void
    {
        $fresh = Deal::query()->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();
        if (! $fresh->isOpen() || ! $this->canManage($fresh, $by) || ! $fresh->pin->isActive()) {
            throw new CrmForbidden('Cannot change this deal.');
        }
        $deal->setRawAttributes($fresh->getAttributes(), true);
    }

    /** Права проверялись по модели до блокировки: пин мог закрыться или сменить держателя. */
    private function lockActivePin(ListingPin $pin, User $by): void
    {
        $fresh = ListingPin::query()->whereKey($pin->getKey())->lockForUpdate()->firstOrFail();
        if (! $fresh->isActive() || $fresh->user_id !== $pin->user_id || ! $this->pins->canManage($fresh, $by)) {
            throw new CrmForbidden('Pin changed, reload it.');
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
