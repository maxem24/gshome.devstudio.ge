<?php

namespace App\Crm;

use App\Access\OrgAccess;
use App\Crm\Events\ObjectClosed;
use App\Crm\Events\PinClosed;
use App\Crm\Events\PinTransferred;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Exceptions\PinTaken;
use App\Enums\DealStatus;
use App\Enums\Role;
use App\Enums\UnavailableReason;
use App\Models\Deal;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\User;
use App\RealEstate\Listing;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Объекты в работе: взять, передать, закрыть. Правила «кто за кого» — OrgAccess::canActFor. */
class Pins
{
    /** @deprecated Ключ считает ListingObjects::keyFor. */
    public function keyFor(Listing $listing): string
    {
        return $this->objects()->keyFor($listing);
    }

    /**
     * Самый старый активный пин открытого объекта этой квартиры.
     *
     * @deprecated Читатели переезжают на объекты (ListingObjects::findOpen, Pins::activeOf).
     */
    public function activeFor(Listing $listing): ?ListingPin
    {
        return $this->activeForIds($listing->id, $listing->clusterId);
    }

    /**
     * То же по id и группе без самого объявления: снятое API уже не отдаёт, а участие живёт.
     *
     * @deprecated См. activeFor.
     */
    public function activeForIds(int $listingId, ?int $clusterId): ?ListingPin
    {
        $object = $this->objects()->findOpen($listingId, $clusterId);

        return $object === null ? null : $object->activePins()->oldest('id')->first();
    }

    /** Активный пин этого человека в объекте. */
    public function mine(ListingObject $object, User $user): ?ListingPin
    {
        return ListingPin::query()
            ->where('object_id', $object->id)
            ->where('user_id', $user->id)
            ->where('status', ListingPin::ACTIVE)
            ->first();
    }

    /** Пин, которым смотрящий вправе управлять: свой или самый старый из видимых ему (руководитель). */
    public function managed(ListingObject $object, User $viewer): ?ListingPin
    {
        return $this->mine($object, $viewer)
            ?? $this->activeOf($object)->first(fn (ListingPin $pin): bool => $this->canManage($pin, $viewer));
    }

    /** @return Collection<int, ListingPin> */
    public function activeOf(ListingObject $object): Collection
    {
        return ListingPin::query()
            ->where('object_id', $object->id)
            ->where('status', ListingPin::ACTIVE)
            ->with('holder')
            ->orderBy('id')
            ->get();
    }

    /** Взять объект в работу. Другие агенты не мешают; отказ — только если вы сами уже его ведёте. */
    public function take(Listing $listing, User $by): ListingPin
    {
        $direction = $listing->direction();
        if ($direction === null || ! in_array($direction, OrgAccess::directions($by), true)) {
            throw new CrmForbidden('Direction is not available to this user.');
        }

        // Порядок блокировок: сначала openFor (может склеивать объекты), потом lock. Закрыли между ними — один повтор.
        for ($attempt = 1; ; $attempt++) {
            $object = $this->objects()->openFor($listing);
            try {
                return DB::transaction(function () use ($listing, $by, $direction, $object): ListingPin {
                    $object = $this->objects()->lock($object);
                    if ($existing = $this->mine($object, $by)) {
                        throw new PinTaken($existing);
                    }

                    $pin = ListingPin::query()->create([
                        'listing_id' => $listing->id,
                        'cluster_id' => $listing->clusterId,
                        'pin_key' => $this->objects()->keyFor($listing),
                        'object_id' => $object->id,
                        'direction' => $direction,
                        'user_id' => $by->id,
                        'status' => ListingPin::ACTIVE,
                        'last_activity_at' => now(),
                    ]);
                    $pin->setRelation('object', $object);
                    $this->snapshot($pin, $listing);
                    Journal::record(Journal::PIN_TAKEN, $pin, ['listing_id' => $listing->id, 'cluster_id' => $listing->clusterId, 'object_id' => $object->id], $by);

                    return $pin;
                });
            } catch (CrmForbidden $e) {
                if ($attempt >= 2) {
                    throw $e;
                }
            }
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
            $object = $this->lockObjectOf($pin);
            $this->lockActive($pin);
            if ($this->mine($object, $to) !== null) {
                throw new CrmForbidden('Recipient already works on this object.');
            }
            $pin->update(['user_id' => $to->id, 'last_activity_at' => now()]);
            Journal::record(Journal::PIN_TRANSFERRED, $pin, ['listing_id' => $pin->listing_id, 'from_user_id' => $from->id, 'to_user_id' => $to->id], $by);
            PinTransferred::dispatch($pin, $from, $to, $by);
        });
    }

    /**
     * «Объект недоступен». Закрывающая причина (сдан в другом месте, фейк) закрывает объект для всех,
     * остальные — только участие этого агента.
     */
    public function markUnavailable(ListingPin $pin, UnavailableReason $reason, User $by): void
    {
        $this->assertCanManage($pin, $by);

        DB::transaction(function () use ($pin, $reason, $by): void {
            $object = $this->lockObjectOf($pin);
            $this->lockActive($pin);
            $this->closePin($pin, 'unavailable', Journal::PIN_UNAVAILABLE, $by, $reason);
            if ($reason->closesObject()) {
                $this->closeObject($object, $reason->value, $by, except: $pin);
            } else {
                $this->objects()->closeIfAbandoned($object);
            }
        });
    }

    /** Снять своё участие. Нельзя с открытыми сделками; последний ушедший закрывает объект («abandoned»). */
    public function release(ListingPin $pin, User $by): void
    {
        $this->assertCanManage($pin, $by);

        DB::transaction(function () use ($pin, $by): void {
            $object = $this->lockObjectOf($pin);
            $this->lockActive($pin);
            // Отпустить объект с живой сделкой нельзя; проверка под блокировкой пина не гонится с Deals::open.
            if ($pin->deals()->where('status', DealStatus::Open)->exists()) {
                throw new CrmForbidden('Pin has open deals.');
            }
            $this->closePin($pin, 'released', Journal::PIN_RELEASED, $by);
            $this->objects()->closeIfAbandoned($object);
        });
    }

    /** Сделка по этому пину состоялась: пин закрывается «won», объект закрывается для всех остальных. */
    public function win(ListingPin $pin, Deal $deal, User $by): void
    {
        $this->assertCanManage($pin, $by);

        DB::transaction(function () use ($pin, $deal, $by): void {
            $object = $this->lockObjectOf($pin);
            $this->lockActive($pin);
            $this->closePin($pin, 'won', Journal::PIN_WON, $by);
            $this->closeObject($object, 'won', $by, except: $pin, wonDeal: $deal);
        });
    }

    /**
     * Закрыть объект для всех. Вызывать внутри транзакции, держа блокировку объекта (ListingObjects::lock).
     * Остальные активные пины закрываются «closed_by_other», их открытые сделки — «недоступен» (слушатель PinClosed).
     */
    public function closeObject(ListingObject $object, string $reason, User $by, ?ListingPin $except = null, ?Deal $wonDeal = null): void
    {
        $object->update([
            'status' => ListingObject::CLOSED,
            'closed_reason' => $reason,
            'closed_at' => now(),
            'closed_by' => $by->id,
            'won_deal_id' => $wonDeal?->id,
        ]);

        $others = ListingPin::query()
            ->where('object_id', $object->id)
            ->where('status', ListingPin::ACTIVE)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->id))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($others as $other) {
            $this->closePin($other, 'closed_by_other', null, $by);
        }

        Journal::record(Journal::OBJECT_CLOSED, $object, [
            'listing_id' => $object->listing_id,
            'reason' => $reason,
            'closed_pins' => $others->pluck('id')->all(),
        ], $by);
        ObjectClosed::dispatch($object, $by, $reason, $others);
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

    /**
     * Имя держателя для смотрящего — по тем же правилам, что у объявлений: свои люди и владелец
     * группы видят имя, остальные — нет (null → «у другого агента группы»).
     */
    public function holderLabel(ListingPin $pin, User $viewer): ?string
    {
        $holder = $pin->holder;
        $named = $holder->id === $viewer->id
            || $viewer->role === Role::Owner
            || in_array((int) $holder->company_id, OrgAccess::identifiableCompanyIds($viewer), true);

        return $named ? $holder->name : null;
    }

    /**
     * Снимок объявления теперь живёт на объекте; колонки на пине пишем, пока их читает подбор пар.
     * Пишет, только если что-то изменилось.
     *
     * @deprecated Объект снимает ListingObjects::snapshot.
     *
     * @param  bool  $touch  ночная проверка: отметить снимок свежим и без изменений
     * @return bool true — снимок пина записан
     */
    public function snapshot(ListingPin $pin, Listing $listing, bool $touch = false): bool
    {
        if ($pin->object !== null) {
            $this->objects()->snapshot($pin->object, $listing, $touch);
        }

        $price = $listing->priceUsd === null ? null : number_format($listing->priceUsd, 2, '.', '');
        $changed = $pin->snapshot_at === null
            || $pin->price_usd !== $price
            || $pin->rooms !== $listing->rooms
            || $pin->subdistrict_id !== $listing->subdistrictId;
        if (! $changed) {
            if ($touch) {
                $pin->update(['snapshot_at' => now()]);
            }

            return false;
        }

        $pin->update([
            'price_usd' => $price,
            'rooms' => $listing->rooms,
            'subdistrict_id' => $listing->subdistrictId,
            'title' => $listing->title ?? $pin->title,
            'snapshot_at' => now(),
        ]);

        return true;
    }

    /**
     * Кому собственник готов сдать. Тот же список, что «Кого ищет» у клиента.
     *
     * @param  array<string, mixed>  $input
     */
    public function savePreferences(ListingPin $pin, array $input, User $by): void
    {
        $this->assertCanManage($pin, $by);
        $pin->update(['preferences' => ClientProfile::cleanLandlord($input)]);
    }

    private function objects(): ListingObjects
    {
        return app(ListingObjects::class);
    }

    /** Закрыть один пин: статус, журнал (если задан), событие. Блокировки и права — на вызывающем. */
    private function closePin(ListingPin $pin, string $reason, ?string $event, User $by, ?UnavailableReason $why = null): void
    {
        $pin->update(['status' => ListingPin::CLOSED, 'closed_reason' => $reason, 'unavailable_reason' => $why, 'closed_at' => now()]);
        if ($event !== null) {
            Journal::record($event, $pin, ['listing_id' => $pin->listing_id, 'reason' => $reason] + ($why !== null ? ['why' => $why->value] : []), $by);
        }
        PinClosed::dispatch($pin, $reason);
    }

    /** Заблокировать объект пина; объект читаем свежим: после склейки пин мог переехать. */
    private function lockObjectOf(ListingPin $pin): ListingObject
    {
        $objectId = ListingPin::query()->whereKey($pin->getKey())->value('object_id');

        return $this->objects()->lock(ListingObject::query()->findOrFail($objectId));
    }

    /** Перечитать под блокировкой: параллельная передача или закрытие могли изменить пин. */
    private function lockActive(ListingPin $pin): void
    {
        $fresh = ListingPin::query()->whereKey($pin->getKey())->lockForUpdate()->firstOrFail();
        // Права проверялись по модели до блокировки: если держатель сменился, проверка устарела.
        if (! $fresh->isActive() || $fresh->user_id !== $pin->user_id) {
            throw new CrmForbidden('Pin changed, reload it.');
        }
    }

    private function assertCanManage(ListingPin $pin, User $by): void
    {
        if (! $pin->isActive() || ! $this->canManage($pin, $by)) {
            throw new CrmForbidden('Cannot manage this pin.');
        }
    }
}
