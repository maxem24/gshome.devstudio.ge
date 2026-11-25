<?php

namespace App\Crm;

use App\Crm\Exceptions\CrmForbidden;
use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\RealEstate\Listing;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Объекты: общая карточка группы дублей. Найти, создать, склеить, заблокировать, снимок. */
class ListingObjects
{
    public function keyFor(Listing $listing): string
    {
        return $listing->clusterId !== null ? 'c:'.$listing->clusterId : 'l:'.$listing->id;
    }

    /**
     * Открытый объект этой квартиры. Ищем и по ключу группы, и по id самого объявления:
     * после перестройки групп в Real Estate ключ мог устареть. Если нашлось несколько — склеиваем.
     */
    public function findOpen(int $listingId, ?int $clusterId): ?ListingObject
    {
        $key = $clusterId !== null ? 'c:'.$clusterId : 'l:'.$listingId;

        /** @var ListingObject|null $found */
        $found = null;
        $objects = ListingObject::query()
            ->where('status', ListingObject::OPEN)
            ->where(fn ($q) => $q->where('object_key', $key)->orWhere('listing_id', $listingId))
            ->oldest('id')
            ->get();

        foreach ($objects as $object) {
            if ($found === null) {
                $found = $object;

                continue;
            }
            $this->merge($found, $object);
        }

        return $found?->refresh();
    }

    public function openFor(Listing $listing): ListingObject
    {
        if ($existing = $this->findOpen($listing->id, $listing->clusterId)) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($listing): ListingObject {
                $object = ListingObject::query()->create([
                    'object_key' => $this->keyFor($listing),
                    'listing_id' => $listing->id,
                    'cluster_id' => $listing->clusterId,
                    'direction' => $listing->direction(),
                    'status' => ListingObject::OPEN,
                ]);
                $this->snapshot($object, $listing);

                return $object;
            });
        } catch (UniqueConstraintViolationException) {
            // Параллельный запрос успел создать объект между проверкой и вставкой.
            return $this->findOpen($listing->id, $listing->clusterId) ?? throw new CrmForbidden('Object changed, reload it.');
        }
    }

    /** Свежая строка под блокировкой: закрытый или склеенный объект менять нельзя. */
    public function lock(ListingObject $object): ListingObject
    {
        $fresh = ListingObject::query()->whereKey($object->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->merged_into_id !== null) {
            throw new CrmForbidden('Object merged into #'.$fresh->merged_into_id.'.');
        }
        if (! $fresh->isOpen()) {
            throw new CrmForbidden('Object closed.');
        }

        return $fresh;
    }

    /**
     * Склеить $gone в $keep: пины переезжают, $gone закрывается. Если у агента теперь два
     * активных пина в $keep, остаётся старший, младший закрывается «merged», его открытые сделки
     * уходят на старший.
     */
    public function merge(ListingObject $keep, ListingObject $gone): void
    {
        if ($keep->id === $gone->id) {
            return;
        }

        DB::transaction(function () use ($keep, $gone): void {
            // Блокировки по возрастанию id — две склейки наоборот не взаимоблокируются.
            $locked = ListingObject::query()->whereKey([$keep->id, $gone->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $keepRow = $locked->get($keep->id);
            $goneRow = $locked->get($gone->id);
            if ($keepRow === null || $goneRow === null || ! $goneRow->isOpen()) {
                return;
            }

            $keepPins = ListingPin::query()->where('object_id', $keepRow->id)->where('status', ListingPin::ACTIVE)->get()->keyBy('user_id');
            $gonePins = ListingPin::query()->where('object_id', $goneRow->id)->where('status', ListingPin::ACTIVE)->orderBy('id')->get();

            foreach ($gonePins as $pin) {
                $other = $keepPins->get($pin->user_id);
                if ($other === null) {
                    $keepPins->put($pin->user_id, $pin);

                    continue;
                }
                // Старший пин остаётся, младший закрывается; сделки — на старший.
                [$older, $younger] = $other->id < $pin->id ? [$other, $pin] : [$pin, $other];
                $this->moveDeals($younger, $older);
                $younger->update(['status' => ListingPin::CLOSED, 'closed_reason' => 'merged', 'closed_at' => now()]);
                $keepPins->put($pin->user_id, $older);
            }

            ListingPin::query()->where('object_id', $goneRow->id)->update(['object_id' => $keepRow->id]);
            $goneRow->update([
                'status' => ListingObject::CLOSED,
                'closed_reason' => 'merged_into',
                'closed_at' => now(),
                'merged_into_id' => $keepRow->id,
            ]);
        });
    }

    /**
     * Открытые сделки младшего пина — на старший. Индекс deals_pin_client_unique не смотрит на статус,
     * поэтому сделка клиента, который уже есть на старшем, не переезжает: закрывается «недоступна»
     * и остаётся на закрытом младшем пине.
     */
    private function moveDeals(ListingPin $from, ListingPin $to): void
    {
        $deals = Deal::query()->where('listing_pin_id', $from->id)->where('status', DealStatus::Open)->get();
        foreach ($deals as $deal) {
            $duplicate = Deal::query()->where('listing_pin_id', $to->id)->where('client_id', $deal->client_id)->exists();
            if ($duplicate) {
                $deal->update(['status' => DealStatus::Unavailable, 'closed_at' => now()]);

                continue;
            }
            $deal->update(['listing_pin_id' => $to->id]);
        }
    }

    /**
     * Снимок цены, комнат и микрорайона объявления — для подбора пар без запросов к API.
     * Пишет, только если что-то из этого изменилось; заголовок зависит от языка смотрящего,
     * поэтому сам по себе запись не вызывает.
     *
     * @param  bool  $touch  ночная проверка: отметить снимок свежим и без изменений
     * @return bool true — снимок записан
     */
    public function snapshot(ListingObject $object, Listing $listing, bool $touch = false): bool
    {
        $usd = $listing->priceUsd === null ? null : number_format($listing->priceUsd, 2, '.', '');
        $gel = $listing->priceGel === null ? null : number_format($listing->priceGel, 2, '.', '');
        $changed = $object->snapshot_at === null
            || $object->price_usd !== $usd
            || $object->price_gel !== $gel
            || $object->rooms !== $listing->rooms
            || $object->subdistrict_id !== $listing->subdistrictId;
        if (! $changed) {
            if ($touch) {
                $object->update(['snapshot_at' => now()]);
            }

            return false;
        }

        $object->update([
            'price_usd' => $usd,
            'price_gel' => $gel,
            'rooms' => $listing->rooms,
            'subdistrict_id' => $listing->subdistrictId,
            'title' => $listing->title ?? $object->title,
            'snapshot_at' => now(),
        ]);

        return true;
    }

    /** Некому работать — нет активных участий: объект закрывается «abandoned». */
    public function closeIfAbandoned(ListingObject $object): void
    {
        DB::transaction(function () use ($object): void {
            $fresh = ListingObject::query()->whereKey($object->getKey())->lockForUpdate()->first();
            if ($fresh === null || ! $fresh->isOpen() || $fresh->activePins()->exists()) {
                return;
            }
            $fresh->update(['status' => ListingObject::CLOSED, 'closed_reason' => 'abandoned', 'closed_at' => now()]);
        });
        $object->refresh();
    }
}
