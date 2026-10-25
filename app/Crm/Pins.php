<?php

namespace App\Crm;

use App\Access\OrgAccess;
use App\Crm\Events\PinClosed;
use App\Crm\Events\PinTransferred;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Exceptions\PinTaken;
use App\Enums\DealStatus;
use App\Models\ListingPin;
use App\Models\User;
use App\RealEstate\Listing;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Объекты в работе: взять, передать, закрыть. Правила «кто за кого» — OrgAccess::canActFor. */
class Pins
{
    public function keyFor(Listing $listing): string
    {
        return $listing->clusterId !== null ? 'c:'.$listing->clusterId : 'l:'.$listing->id;
    }

    /**
     * Активное закрепление этой квартиры. Ищем и по текущему ключу группы, и по id самого
     * объявления: после перестройки групп в Real Estate ключ мог устареть (спецификация).
     */
    public function activeFor(Listing $listing): ?ListingPin
    {
        return ListingPin::query()
            ->where('status', ListingPin::ACTIVE)
            ->where(fn ($q) => $q->where('pin_key', $this->keyFor($listing))->orWhere('listing_id', $listing->id))
            ->oldest('id')
            ->first();
    }

    public function take(Listing $listing, User $by): ListingPin
    {
        $direction = $listing->direction();
        if ($direction === null || ! in_array($direction, OrgAccess::directions($by), true)) {
            throw new CrmForbidden('Direction is not available to this user.');
        }

        if ($existing = $this->activeFor($listing)) {
            throw new PinTaken($existing);
        }

        try {
            return DB::transaction(function () use ($listing, $by, $direction): ListingPin {
                $pin = ListingPin::query()->create([
                    'listing_id' => $listing->id,
                    'cluster_id' => $listing->clusterId,
                    'pin_key' => $this->keyFor($listing),
                    'direction' => $direction,
                    'user_id' => $by->id,
                    'status' => ListingPin::ACTIVE,
                    'last_activity_at' => now(),
                ]);
                Journal::record(Journal::PIN_TAKEN, $pin, ['listing_id' => $listing->id, 'cluster_id' => $listing->clusterId], $by);

                return $pin;
            });
        } catch (UniqueConstraintViolationException) {
            // Второй агент успел между проверкой и вставкой — держит индекс.
            throw new PinTaken($this->activeFor($listing));
        }
    }

    public function transfer(ListingPin $pin, User $to, User $by): void
    {
        $this->assertCanManage($pin, $by);
        if (! $this->canReceive($pin, $to, $by)) {
            throw new CrmForbidden('Cannot transfer to this user.');
        }

        $from = $pin->holder;
        DB::transaction(function () use ($pin, $to, $by, $from): void {
            $this->lockActive($pin);
            $pin->update(['user_id' => $to->id, 'last_activity_at' => now()]);
            Journal::record(Journal::PIN_TRANSFERRED, $pin, ['listing_id' => $pin->listing_id, 'from_user_id' => $from->id, 'to_user_id' => $to->id], $by);
            PinTransferred::dispatch($pin, $from, $to);
        });
    }

    public function markUnavailable(ListingPin $pin, User $by): void
    {
        $this->close($pin, 'unavailable', Journal::PIN_UNAVAILABLE, $by);
    }

    public function release(ListingPin $pin, User $by): void
    {
        // Отпустить объект с живой сделкой нельзя: сделку сначала выигрывают или проигрывают.
        if ($pin->deals()->where('status', DealStatus::Open)->exists()) {
            throw new CrmForbidden('Pin has open deals.');
        }
        $this->close($pin, 'released', Journal::PIN_RELEASED, $by);
    }

    public function touch(ListingPin $pin, ?CarbonInterface $at = null): void
    {
        $pin->forceFill(['last_activity_at' => $at ?? now()])->save();
    }

    public function canManage(ListingPin $pin, User $user): bool
    {
        return $pin->user_id === $user->id || OrgAccess::canSee($user, $pin->holder);
    }

    /**
     * Кому можно передать. Руководитель — тем, за кого действует. Агент-держатель сам никого
     * не видит, поэтому передаёт в пределах ступени своего тимлида — коллегам своей группы.
     */
    public function canReceive(ListingPin $pin, User $to, User $by): bool
    {
        if ($to->id === $pin->user_id || ! in_array($pin->direction, OrgAccess::directions($to), true)) {
            return false;
        }

        return OrgAccess::canActFor(OrgAccess::handOverScope($by, $pin->user_id), $to);
    }

    /** @param  'won'|'unavailable'|'released'  $reason */
    public function close(ListingPin $pin, string $reason, string $event, User $by): void
    {
        $this->assertCanManage($pin, $by);

        DB::transaction(function () use ($pin, $reason, $event, $by): void {
            $this->lockActive($pin);
            $pin->update(['status' => ListingPin::CLOSED, 'closed_reason' => $reason, 'closed_at' => now()]);
            Journal::record($event, $pin, ['listing_id' => $pin->listing_id, 'reason' => $reason], $by);
            PinClosed::dispatch($pin, $reason);
        });
    }

    /** Перечитать под блокировкой: параллельная передача или закрытие уже могли закрыть пин. */
    private function lockActive(ListingPin $pin): void
    {
        $fresh = ListingPin::query()->whereKey($pin->getKey())->lockForUpdate()->firstOrFail();
        if (! $fresh->isActive()) {
            throw new CrmForbidden('Pin is already closed.');
        }
    }

    private function assertCanManage(ListingPin $pin, User $by): void
    {
        if (! $pin->isActive() || ! $this->canManage($pin, $by)) {
            throw new CrmForbidden('Cannot manage this pin.');
        }
    }
}
