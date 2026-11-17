<?php

namespace App\Crm;

use App\RealEstate\RealEstateClient;

/** Микрорайоны Тбилиси из тех же справочников, что фильтр объектов. */
final class ClientPlaces
{
    /**
     * @return array<int, string> id микрорайона ⇒ «район · микрорайон»
     */
    public static function options(): array
    {
        try {
            $client = app(RealEstateClient::class);
            $cities = $client->dictionary('cities');
        } catch (\Throwable) {
            return [];
        }

        $city = $cities->first(fn (array $row): bool => ($row['slug'] ?? null) === 'tbilisi') ?? $cities->first();
        if (! is_array($city) || ! isset($city['id'])) {
            return [];
        }

        try {
            $districts = $client->dictionary('districts', ['city_id' => (int) $city['id']]);
        } catch (\Throwable) {
            return [];
        }

        $options = [];
        foreach ($districts as $district) {
            if (! is_array($district) || ! isset($district['id'])) {
                continue;
            }
            try {
                $subs = $client->dictionary('subdistricts', ['district_id' => (int) $district['id']]);
            } catch (\Throwable) {
                continue;
            }
            foreach ($subs as $sub) {
                if (! is_array($sub) || ! isset($sub['id'])) {
                    continue;
                }
                $options[(int) $sub['id']] = RealEstateClient::title($district).' · '.RealEstateClient::title($sub);
            }
        }

        return $options;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    public static function labels(array $ids): array
    {
        $options = self::options();

        return array_map(
            fn (int $id): string => $options[$id] ?? '#'.$id,
            $ids,
        );
    }
}
