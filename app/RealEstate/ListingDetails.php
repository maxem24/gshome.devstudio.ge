<?php

namespace App\RealEstate;

/**
 * Дополнительная карточка объявления: каждый известный пункт виден всегда.
 * Есть значение — подпись с ним, нет — только название пункта.
 */
final class ListingDetails
{
    /** @var array<string, string> slug удобства ⇒ имя иконки Filament */
    private const AMENITY_ICONS = [
        'elevator' => 'heroicon-o-arrows-up-down',
        'gas' => 'heroicon-o-fire',
        'internet' => 'heroicon-o-signal',
        'tv' => 'heroicon-o-tv',
        'furniture' => 'heroicon-o-cube',
        'conditioner' => 'heroicon-o-cloud',
        'heating' => 'heroicon-o-fire',
        'hot_water' => 'heroicon-o-beaker',
        'washing_machine' => 'heroicon-o-arrow-path',
        'fridge' => 'heroicon-o-cube',
        'garage' => 'heroicon-o-truck',
        'storage' => 'heroicon-o-archive-box',
        'security_alarm' => 'heroicon-o-shield-check',
        'iron_door' => 'heroicon-o-lock-closed',
        'cable_tv' => 'heroicon-o-tv',
        'wifi' => 'heroicon-o-wifi',
        'pool' => 'heroicon-o-beaker',
        'balcony' => 'heroicon-o-sun',
        'basement' => 'heroicon-o-archive-box',
        'pet_friendly' => 'heroicon-o-heart',
        'glazed_windows' => 'heroicon-o-window',
        'truck_elevator' => 'heroicon-o-truck',
        'water' => 'heroicon-o-beaker',
        'sewage' => 'heroicon-o-beaker',
        'alarm' => 'heroicon-o-bell-alert',
        'ventilation' => 'heroicon-o-cloud',
        'fire_place' => 'heroicon-o-fire',
        'phone' => 'heroicon-o-phone',
        'guard' => 'heroicon-o-user',
        'tub' => 'heroicon-o-sparkles',
        'sauna' => 'heroicon-o-fire',
        'refrigerator' => 'heroicon-o-cube',
        'dishwasher_machine' => 'heroicon-o-sparkles',
        'stove' => 'heroicon-o-fire',
        'oven' => 'heroicon-o-fire',
        'living_room' => 'heroicon-o-home',
        'sewerage' => 'heroicon-o-beaker',
        'electricity' => 'heroicon-o-bolt',
        'way' => 'heroicon-o-map-pin',
        'home' => 'heroicon-o-home-modern',
        'spa' => 'heroicon-o-sparkles',
        'cellar' => 'heroicon-o-archive-box',
        'bar' => 'heroicon-o-beaker',
        'gym' => 'heroicon-o-bolt',
        'coded_door' => 'heroicon-o-key',
        'fenced' => 'heroicon-o-shield-check',
        'gate' => 'heroicon-o-lock-closed',
        'fruit_tree' => 'heroicon-o-sun',
        'grill' => 'heroicon-o-fire',
        'tungsten' => 'heroicon-o-light-bulb',
        'bed' => 'heroicon-o-moon',
        'sofa' => 'heroicon-o-cube',
        'table' => 'heroicon-o-squares-2x2',
        'chair' => 'heroicon-o-cube',
        'kitchen' => 'heroicon-o-fire',
        'for_parties' => 'heroicon-o-sparkles',
        'pets_allowed' => 'heroicon-o-heart',
        'storeroom' => 'heroicon-o-archive-box',
        'airbnb_booking_account' => 'heroicon-o-home',
        'investment' => 'heroicon-o-banknotes',
        'article' => 'heroicon-o-document-check',
        'possible_divide' => 'heroicon-o-arrows-right-left',
        'for_special_people' => 'heroicon-o-user',
        'swimming_pool_open' => 'heroicon-o-sun',
        'swimming_pool_closed' => 'heroicon-o-home',
        'concierge' => 'heroicon-o-user',
        'schlagbaum' => 'heroicon-o-lock-closed',
        'fire_system' => 'heroicon-o-fire',
    ];

    /**
     * @param  list<array{slug: string, title: string}>  $catalog  справочник удобств
     * @return list<array{icon: string, label: string, active: bool}>
     */
    public static function additional(Listing $listing, array $catalog): array
    {
        $items = [
            self::row('heroicon-o-moon', __('gshome.listings.bedrooms'), self::count($listing->bedrooms)),
            self::row('heroicon-o-beaker', __('gshome.listings.bathrooms'), self::count($listing->bathrooms)),
            self::row('heroicon-o-arrows-up-down', __('gshome.listings.ceiling'), self::meters($listing->ceilingHeight)),
            self::row('heroicon-o-sun', __('gshome.listings.balconies'), self::count($listing->balconies)),
            self::row('heroicon-o-fire', __('gshome.listings.kitchen_area'), self::squareMeters($listing->areaKitchen)),
            self::row('heroicon-o-globe-alt', __('gshome.listings.yard_area'), self::squareMeters($listing->yardArea)),
            self::row('heroicon-o-calendar', __('gshome.listings.built'), self::count($listing->buildYear)),
            self::row('heroicon-o-paint-brush', __('gshome.listings.condition'), $listing->condition),
            self::row('heroicon-o-building-office', __('gshome.listings.building_status'), $listing->buildingStatus),
            self::row('heroicon-o-map-pin', __('gshome.listings.metro_label'), $listing->subwayStations === [] ? null : implode(', ', $listing->subwayStations)),
        ];

        $present = [];
        foreach ($listing->amenities as $amenity) {
            $present[$amenity['slug']] = $amenity['title'];
        }

        $known = [];
        foreach ($catalog as $amenity) {
            $slug = $amenity['slug'];
            if ($slug === '') {
                continue;
            }
            $known[$slug] = true;
            $items[] = self::row(self::AMENITY_ICONS[$slug] ?? 'heroicon-o-check', $amenity['title'], isset($present[$slug]) ? $amenity['title'] : null);
        }

        foreach ($present as $slug => $title) {
            if (isset($known[$slug])) {
                continue;
            }
            $items[] = self::row(self::AMENITY_ICONS[$slug] ?? 'heroicon-o-check', $title, $title);
        }

        return $items;
    }

    /** @return array{icon: string, label: string, active: bool} */
    private static function row(string $icon, string $name, ?string $value): array
    {
        $active = $value !== null && $value !== '';

        return [
            'icon' => $icon,
            'label' => $active && $value !== $name ? $name.': '.$value : $name,
            'active' => $active,
        ];
    }

    private static function count(?int $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function meters(?float $value): ?string
    {
        return $value === null ? null : __('gshome.listings.meters', ['value' => self::number($value)]);
    }

    /** Площадь «65 м²» — и в шапке карточки, и в блоке «Дополнительно». */
    public static function squareMeters(?float $value): ?string
    {
        return $value === null ? null : __('gshome.listings.square_meters', ['value' => self::number($value)]);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
