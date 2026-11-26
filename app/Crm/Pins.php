<?php

namespace App\Crm;

use App\Access\OrgAccess;
use App\Crm\Events\ObjectClosed;
use App\Crm\Events\PinClosed;
use App\Crm\Events\PinTransferred;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Exceptions\ObjectClosed as ObjectClosedException;
use App\Crm\Exceptions\PinTaken;
use App\Crm\Exceptions\RecipientOnObject;
use App\Enums\CommissionKind;
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
                    // Снимок — только с самого объявления объекта: копия группы может стоить иначе.
                    if ($object->listing_id === $listing->id) {
                        $this->objects()->snapshot($object, $listing);
                    }
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
            $object = $this->objects()->lockOfPin($pin);
            $this->lockActive($pin);
            if ($this->mine($object, $to) !== null) {
                throw new RecipientOnObject;
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

        if ($reason->closesObject()) {
            $this->objects()->unite($pin);
        }
        DB::transaction(function () use ($pin, $reason, $by): void {
            $object = $this->objects()->lockOfPin($pin);
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
            $object = $this->objects()->lockOfPin($pin);
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
            $object = $this->objects()->lockOfPin($pin);
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
        // Руководитель закрыл от имени агента: сам агент тоже должен узнать (его пина нет среди закрытых здесь).
        $acting = $except !== null && $except->user_id !== $by->id ? $except->holder : null;
        ObjectClosed::dispatch($object, $by, $reason, $others, $acting);
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
        return $this->nameFor($pin->holder, $viewer);
    }

    /** Имя человека для смотрящего: правило «видимости держателя», без привязки к пину. */
    public function nameFor(User $holder, User $viewer): ?string
    {
        $named = $holder->id === $viewer->id
            || $viewer->role === Role::Owner
            || in_array((int) $holder->company_id, OrgAccess::identifiableCompanyIds($viewer), true);

        return $named ? $holder->name : null;
    }

    /**
     * Понятный отказ для экрана: закрытый объект называет закрывшего (если смотрящему можно знать имя),
     * передача тому, кто уже ведёт объект, — свой текст. Остальное — общий ключ $fallback.
     */
    public function refusal(CrmForbidden $e, User $viewer, string $fallback): string
    {
        if ($e instanceof RecipientOnObject) {
            return __('gshome.crm.pins.transfer_already_on_object');
        }
        if ($e instanceof ObjectClosedException) {
            $object = $e->object;
            $by = $object->closed_by === null ? null : User::query()->find($object->closed_by);
            $name = $by === null ? null : $this->nameFor($by, $viewer);
            $reason = in_array($object->closed_reason, ['won', 'rented_elsewhere', 'fake'], true) ? (string) $object->closed_reason : 'abandoned';
            $key = 'gshome.crm.pins.object_closed_'.$reason.($name === null && $reason !== 'abandoned' ? '_anon' : '');

            return __($key, ['name' => $name]);
        }

        return __($fallback);
    }

    /**
     * Договор с собственником на этом участии. Пустые цена и комиссия снимают договор.
     * Дата и автор первого сохранения не меняются.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveAgreement(ListingPin $pin, array $input, User $by): void
    {
        $this->assertCanManage($pin, $by);
        $fields = $this->agreementFields($input);

        DB::transaction(function () use ($pin, $by, $fields, $input): void {
            $this->lockActive($pin);
            $pin->refresh();
            if ($fields === null) {
                $pin->update($this->clearedAgreement($this->text($input['agreement_note'] ?? null)));

                return;
            }
            if ($pin->agreed_at === null) {
                $fields['agreed_at'] = now();
                $fields['agreed_by'] = $by->id;
            }
            $pin->update($fields);
            Journal::record(Journal::PIN_AGREEMENT_SAVED, $pin, [
                'listing_id' => $pin->listing_id,
                'commission_kind' => $fields['commission_kind'],
                'commission_percent' => $fields['commission_percent'],
            ], $by);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null null — договора нет, только заметка
     */
    private function agreementFields(array $input): ?array
    {
        $price = $this->text($input['agreed_price'] ?? null);
        $kind = CommissionKind::tryFrom((string) ($input['commission_kind'] ?? ''));
        if ($price === null && $kind === null) {
            return null;
        }
        if ($price === null || $kind === null || ! DealIntake::isMoney($price)) {
            throw new CrmForbidden('Agreement needs a price and a commission.');
        }
        $currency = (string) ($input['agreed_currency'] ?? '');
        if (! in_array($currency, config('crm.currencies'), true)) {
            throw new CrmForbidden('Unknown currency.');
        }

        $percent = $this->intOrNull($input['commission_percent'] ?? null);
        $months = $this->intOrNull($input['commission_months'] ?? null);
        $amount = $this->text($input['commission_amount'] ?? null);
        $commissionNote = $this->text($input['commission_note'] ?? null);
        $owner = $this->text($input['owner_price'] ?? null);
        $term = $this->intOrNull($input['min_term_months'] ?? null);
        if ($owner !== null && ! DealIntake::isMoney($owner)) {
            throw new CrmForbidden('Owner price must be a positive number.');
        }
        if ($term !== null && ! in_array($term, [6, 12], true)) {
            throw new CrmForbidden('Term must be 6 or 12 months.');
        }
        if ($percent !== null && ($percent < 1 || $percent > 100)) {
            throw new CrmForbidden('Percent must be from 1 to 100.');
        }

        $row = [
            'agreed_price' => DealIntake::money($price),
            'agreed_currency' => $currency,
            'owner_price' => $owner === null ? null : DealIntake::money($owner),
            'commission_kind' => $kind->value,
            'commission_percent' => null,
            'commission_months' => null,
            'commission_amount' => null,
            'commission_note' => null,
            'available_from' => $this->text($input['available_from'] ?? null),
            'min_term_months' => $term,
            'myhome_id' => $this->intOrNull($input['myhome_id'] ?? null),
            'ss_id' => $this->intOrNull($input['ss_id'] ?? null),
            'agreement_note' => $this->text($input['agreement_note'] ?? null),
        ];

        $ok = match ($kind) {
            CommissionKind::Percent => $percent !== null,
            CommissionKind::Fixed => $amount !== null && DealIntake::isMoney($amount),
            CommissionKind::PercentMonths => $percent !== null && $months !== null && $months > 0,
            CommissionKind::Other => $commissionNote !== null && mb_strlen($commissionNote) <= 100,
        };
        if (! $ok) {
            throw new CrmForbidden('Commission does not match its kind.');
        }
        $row['commission_percent'] = in_array($kind, [CommissionKind::Percent, CommissionKind::PercentMonths], true) ? $percent : null;
        $row['commission_months'] = $kind === CommissionKind::PercentMonths ? $months : null;
        $row['commission_amount'] = $kind === CommissionKind::Fixed ? DealIntake::money((string) $amount) : null;
        $row['commission_note'] = $kind === CommissionKind::Other ? $commissionNote : null;

        return $row;
    }

    /** @return array<string, mixed> */
    private function clearedAgreement(?string $note): array
    {
        return [
            'agreed_at' => null, 'agreed_by' => null, 'agreed_price' => null, 'agreed_currency' => null,
            'owner_price' => null, 'commission_kind' => null, 'commission_percent' => null,
            'commission_months' => null, 'commission_amount' => null, 'commission_note' => null,
            'available_from' => null, 'min_term_months' => null, 'myhome_id' => null, 'ss_id' => null,
            'agreement_note' => $note,
        ];
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        $text = $this->text($value);

        return $text !== null && ctype_digit($text) ? (int) $text : null;
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
