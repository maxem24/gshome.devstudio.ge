<?php

namespace App\RealEstate;

use App\Access\OrgAccess;
use App\Access\Ownership;
use App\Enums\OwnershipLabel;
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
                'ownership_label' => $own->label->getLabel(),
                'ownership_color' => self::color($own->label),
                'ownership_company' => $own->companyName,
                'ownership_name' => $own->contactName,
                'group_label' => $group === null ? null : 'В группе есть наш сотрудник: '.($group->contactName ?? $group->companyName),
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
