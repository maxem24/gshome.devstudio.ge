<?php

namespace App\RealEstate;

use App\Access\OrgAccess;
use App\Access\Ownership;
use App\Enums\OwnershipLabel;
use App\Enums\SellerCategory;
use App\Models\User;

/**
 * Строки таблицы «Объекты»: данные объявления + метки смотрящего. Метки всей
 * страницы — одним вызовом ownershipMany (номера объявлений и их групп дублей).
 */
final class ListingRows
{
    private const GROUP_PRIORITY = [OwnershipLabel::OurEmployee, OwnershipLabel::LinkedCompany, OwnershipLabel::GroupCompany];

    /**
     * @param  list<Listing>  $listings
     * @return array<int, array<string, mixed>> id объявления ⇒ строка
     */
    public static function build(User $viewer, array $listings): array
    {
        $numbers = collect($listings)->flatMap(fn (Listing $l): array => [$l->contactPhoneE164, ...$l->clusterPhonesE164])->all();
        $resolved = OrgAccess::ownershipMany($viewer, $numbers);

        $rows = [];
        foreach ($listings as $l) {
            $own = $l->contactPhoneE164 !== null ? ($resolved[$l->contactPhoneE164] ?? Ownership::other()) : Ownership::other();
            $group = self::groupOwnership($own, $l, $resolved);
            $copy = self::duplicateCopy($l, $resolved);
            $rows[$l->id] = [
                'id' => $l->id,
                'title' => $l->title ?? '—',
                'address' => $l->address,
                'direction' => $l->direction()?->getLabel(),
                'price' => $l->priceLabel(),
                'facts' => $l->facts(),
                'property_type' => $l->propertyType,
                'district' => $l->district,
                'source' => $l->source === 'myhome_ge' ? 'myhome.ge' : 'ss.ge',
                'created_at' => $l->sourceCreatedAt?->format('d.m.Y'),
                'category' => $l->sellerCategory->getLabel(),
                'image' => $l->mainThumb(),
                'partial' => $l->partial,
                'ownership_label' => $own->label->getLabel(),
                'ownership_color' => self::color($own->label),
                'ownership_company' => $own->companyName,
                'ownership_name' => $own->contactName,
                // Компания, «тот же владелец», группа и дубль — только готовыми строками: до 200 строк
                // едут в снапшоте Livewire при каждом клике, отдельные поля экрану не нужны.
                'ownership_notes' => self::notes($own, $group, $copy),
            ];
        }

        return $rows;
    }

    /** @param  array<string, Ownership>  $resolved */
    public static function groupOwnership(Ownership $own, Listing $listing, array $resolved): ?Ownership
    {
        if ($own->label !== OwnershipLabel::OtherCompany) {
            return null;
        }

        $candidates = collect($listing->clusterPhonesE164)
            ->reject(fn (string $n): bool => $n === $listing->contactPhoneE164)
            ->map(fn (string $n): ?Ownership => $resolved[$n] ?? null)
            ->filter(fn (?Ownership $o): bool => $o !== null && $o->label !== OwnershipLabel::OtherCompany);

        foreach (self::GROUP_PRIORITY as $label) {
            $found = $candidates->first(fn (Ownership $o): bool => $o->label === $label);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Дубль «собственник + наша сторона»: объявление есть у конкретной компании,
     * и у собственника на площадке остаётся своё. Компании не склеиваются в одну.
     *
     * @param  array<string, Ownership>  $resolved
     * @return array{agency_in_group: ?string, owner_still_listed: ?string}
     */
    public static function duplicateCopy(Listing $listing, array $resolved): array
    {
        $empty = ['agency_in_group' => null, 'owner_still_listed' => null];
        $numbers = array_values(array_unique(array_filter([
            $listing->contactPhoneE164,
            ...$listing->clusterPhonesE164,
            ...array_column($listing->clusterListings, 'phone_e164'),
        ], 'is_string')));
        if ($listing->clusterListingsCount < 2 && count($numbers) < 2) {
            return $empty;
        }

        $ownerPresent = $listing->sellerCategory === SellerCategory::Owner
            || collect($listing->clusterListings)->contains(fn (array $member): bool => ($member['seller_category'] ?? null) === SellerCategory::Owner);
        if (! $ownerPresent) {
            return $empty;
        }

        $ownerNumbers = [];
        if ($listing->sellerCategory === SellerCategory::Owner && is_string($listing->contactPhoneE164)) {
            $ownerNumbers[] = $listing->contactPhoneE164;
        }
        foreach ($listing->clusterListings as $member) {
            if (($member['seller_category'] ?? null) === SellerCategory::Owner && is_string($member['phone_e164'] ?? null)) {
                $ownerNumbers[] = $member['phone_e164'];
            }
        }

        $agency = null;
        foreach (self::GROUP_PRIORITY as $label) {
            foreach ($numbers as $number) {
                if (in_array($number, $ownerNumbers, true)) {
                    continue;
                }
                $own = $resolved[$number] ?? null;
                if ($own !== null && $own->label === $label && filled($own->companyName)) {
                    $agency = $own;
                    break 2;
                }
            }
        }
        if ($agency === null) {
            return $empty;
        }

        return [
            'agency_in_group' => __('gshome.listings.agency_has_listing', ['company' => $agency->companyName]),
            'owner_still_listed' => __('gshome.listings.owner_still_listed'),
        ];
    }

    /**
     * @param  array{agency_in_group: ?string, owner_still_listed: ?string}  $copy
     */
    private static function notes(Ownership $own, ?Ownership $group, array $copy): string
    {
        return collect([
            filled($own->companyName) ? __('gshome.listings.company_line', ['name' => $own->companyName]) : null,
            $own->label === OwnershipLabel::LinkedCompany ? __('gshome.ownership.same_owner') : null,
            $own->contactName,
            $group === null ? null : __('gshome.listings.group_member', ['name' => $group->contactName ?? $group->companyName]),
            $copy['agency_in_group'],
            $copy['owner_still_listed'],
        ])->filter()->map(fn (string $line): string => e($line))->implode('<br>');
    }

    public static function color(OwnershipLabel $label): string
    {
        return match ($label) {
            OwnershipLabel::OurEmployee => 'success',
            OwnershipLabel::LinkedCompany => 'info',
            OwnershipLabel::GroupCompany => 'primary',
            OwnershipLabel::OtherCompany => 'gray',
        };
    }
}
