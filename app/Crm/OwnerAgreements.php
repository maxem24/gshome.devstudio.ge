<?php

namespace App\Crm;

use App\Access\OrgAccess;
use App\Enums\CommissionKind;
use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Support\Collection;

/** Договоры по одному объекту: агент видит только свой, руководитель — чужие проценты. */
class OwnerAgreements
{
    /**
     * Строки для руководителя. Агенту список пуст: чужой процент ему не показывают.
     *
     * @return list<array{name: string, url: string, summary: string, expected: ?string, best: bool}>
     */
    public function forObject(ListingObject $object, User $viewer): array
    {
        if ($viewer->role === Role::Agent) {
            return [];
        }

        $pins = ListingPin::query()
            ->where('object_id', $object->id)
            ->whereNotNull('agreed_at')
            ->where(fn ($q) => $q->where('status', ListingPin::ACTIVE)->orWhereIn('closed_reason', ['closed_by_other', 'merged']))
            ->with('holder')
            ->get()
            ->filter(fn (ListingPin $pin): bool => OrgAccess::canSee($viewer, $pin->holder))
            ->values();

        $bestId = $this->bestId($pins);
        $rows = [];
        foreach ($pins as $pin) {
            $rows[] = [
                'name' => $pin->holder->name,
                'url' => UserResource::getUrl('view', ['record' => $pin->holder]),
                'summary' => $this->summary($pin),
                'expected' => $this->expectedGel($pin),
                'best' => $pin->id === $bestId,
                'at' => $pin->agreed_at?->getTimestamp() ?? 0,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['expected'] === null && $b['expected'] === null) {
                return $a['at'] <=> $b['at'];
            }
            if ($a['expected'] === null) {
                return 1;
            }
            if ($b['expected'] === null) {
                return -1;
            }
            $byMoney = bccomp($b['expected'], $a['expected'], 2);

            return $byMoney !== 0 ? $byMoney : ($a['at'] <=> $b['at']);
        });

        return array_map(function (array $row): array {
            unset($row['at']);

            return $row;
        }, $rows);
    }

    public function summary(ListingPin $pin): string
    {
        $sign = $pin->agreed_currency === 'GEL' ? '₾' : '$';
        $price = $this->amount($pin->agreed_price).' '.$sign;
        $kind = $pin->commission_kind;

        $commission = match ($kind) {
            CommissionKind::Percent => $pin->commission_percent.'%',
            CommissionKind::Fixed => $this->amount($pin->commission_amount).' '.$sign,
            CommissionKind::PercentMonths => $pin->commission_percent.'% × '.$pin->commission_months,
            CommissionKind::Other => (string) $pin->commission_note,
            default => '',
        };

        return trim($price.' · '.$commission);
    }

    /** Ожидаемая комиссия в лари. Доллары без курса НБГ в сравнение не входят. */
    public function expectedGel(ListingPin $pin): ?string
    {
        if ($pin->agreed_currency !== 'GEL' || $pin->agreed_price === null) {
            return null;
        }

        $price = (string) $pin->agreed_price;

        return match ($pin->commission_kind) {
            CommissionKind::Percent => bcmul($price, bcdiv((string) $pin->commission_percent, '100', 4), 2),
            CommissionKind::Fixed => number_format((float) $pin->commission_amount, 2, '.', ''),
            CommissionKind::PercentMonths => bcmul(
                bcmul($price, bcdiv((string) $pin->commission_percent, '100', 4), 4),
                (string) $pin->commission_months,
                2,
            ),
            default => null,
        };
    }

    private function amount(mixed $value): string
    {
        return number_format((float) $value, 0, '.', ' ');
    }

    /** @param  Collection<int, ListingPin>  $pins */
    private function bestId(Collection $pins): ?int
    {
        $bestId = null;
        $bestAmount = null;
        $bestAt = null;
        foreach ($pins as $pin) {
            $expected = $this->expectedGel($pin);
            if ($expected === null || $pin->agreed_at === null) {
                continue;
            }
            $better = $bestAmount === null
                || bccomp($expected, $bestAmount, 2) > 0
                || (bccomp($expected, $bestAmount, 2) === 0 && $pin->agreed_at->lt($bestAt));
            if ($better) {
                $bestId = $pin->id;
                $bestAmount = $expected;
                $bestAt = $pin->agreed_at;
            }
        }

        return $bestId;
    }
}
