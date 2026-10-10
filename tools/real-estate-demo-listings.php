<?php

/*
 * Тестовые объявления в ЛОКАЛЬНОЙ базе Real Estate для экрана объявлений GS Home.
 * Код Real Estate не меняет: выполняется его tinker'ом через модель Listing.
 *
 * Запуск (из каталога Real Estate, стек поднят):
 *   cd ~/Sites/real-estate.devstudio.ge
 *   docker compose exec -T app php artisan tinker --execute="$(sed '1,/^ \*\//d' ~/Sites/gshome/tools/real-estate-demo-listings.php)"
 *   docker compose exec -T app php artisan listings:cluster
 * (sed отрезает этот комментарий — номер строки не важен)
 *
 * Удалить:
 *   docker compose exec -T app php artisan tinker --execute="App\Models\Listing::where('external_id','like','gshome-demo-%')->get()->each->delete();"
 *   docker compose exec -T app php artisan listings:cluster
 *
 * Номера «наших» объявлений — номера демо-сотрудников GS Home (OrgDemoSeeder). Если демо-
 * структуру GS Home пересоздать, номера поменяются — обновите массивы $vake и $sab.
 */
use App\Models\Listing;
use Illuminate\Support\Facades\DB;

// Тестовые объявления для экрана объявлений GS Home. Метка: external_id gshome-demo-N.
// Удалить: Listing::where('external_id','like','gshome-demo-%')->get()->each->delete(); затем listings:cluster
mt_srand(20261010);
$images = DB::table('listing_images')->whereNotNull('url')->limit(20)->get(['url', 'url_thumb'])->values();

$vake = ['sale' => ['+995555614795', '+995555926372', '+995555227286', '+995555427692', '+995555148419'],
    'rent' => ['+995555522776', '+995555404195', '+995555728387', '+995555111981', '+995555998450'],
    'office' => '+995555742003'];
$sab = ['sale' => ['+995555060002', '+995555404228', '+995555742205', '+995555982232'],
    'rent' => ['+995555775693', '+995555192248', '+995555203572', '+995555459037'],
    'office' => '+995555694215'];
$national = fn (string $e164) => substr($e164, 4); // 555614795 — как приходит с площадок

$districts = [1 => 'Ваке-Сабуртало', 2 => 'Глдани-Надзаладеви', 3 => 'Исани-Самгори', 4 => 'Дидубе-Чугурети', 5 => 'Крцаниси-Мтацминда', 6 => 'Старый Тбилиси'];
$streets = ['ул. Чавчавадзе', 'ул. Пекина', 'ул. Казбеги', 'пр. Церетели', 'ул. Палиашвили', 'ул. Абашидзе', 'ул. Барнова', 'ул. Гамсахурдиа', 'ул. Марджанишвили', 'ул. Цинамдзгвришвили'];
$agencies = ['Tbilisi Realty', 'Domus Georgia', 'Home.ge Partners', 'Area Group'];
$n = 0;

$make = function (array $o) use (&$n, $images, $districts, $streets) {
    $n++;
    $deal = $o['deal'];
    $rooms = $o['rooms'] ?? mt_rand(1, 5);
    $area = $o['area'] ?? (25 + $rooms * mt_rand(15, 22));
    $usd = $o['usd'] ?? ($deal === 1 ? mt_rand(45, 420) * 1000 : mt_rand(4, 25) * 100);
    $d = $o['district'] ?? array_rand($districts);
    $street = $streets[array_rand($streets)];
    $created = now()->subDays($o['days'] ?? mt_rand(0, 60))->subMinutes(mt_rand(0, 1440));
    $titleRu = ($deal === 1 ? 'Продаётся ' : ($deal === 2 ? 'Сдаётся ' : 'Квартира '))."{$rooms}-комн. квартира, {$area} м², {$districts[$d]}";
    $l = Listing::create(array_merge([
        'source' => $o['source'] ?? 'ss_ge',
        'external_id' => "gshome-demo-{$n}",
        'source_url' => "https://example.test/gshome-demo/{$n}",
        'deal_type_id' => $deal, 'property_type_id' => $o['ptype'] ?? 1, 'city_id' => 1, 'district_id' => $d,
        'address' => "Тбилиси, {$street}, ".mt_rand(1, 120),
        'lat' => 41.70 + mt_rand(0, 600) / 10000, 'lng' => 44.74 + mt_rand(0, 900) / 10000,
        'area_total' => $area, 'rooms' => $rooms, 'bedrooms' => max(1, $rooms - 1),
        'floor' => $o['floor'] ?? mt_rand(1, 12), 'floors_total' => $o['floors'] ?? 14,
        'price_usd' => $usd, 'price_gel' => round($usd * 2.7), 'price_per_sqm_usd' => round($usd / $area, 1),
        'price_per_sqm_gel' => round($usd * 2.7 / $area, 1), 'price_negotiable' => (bool) mt_rand(0, 1),
        'title_ka' => "ბინა {$rooms} ოთახიანი, {$area} მ²",
        'title_ru' => ($o['no_ru'] ?? false) ? null : $titleRu,
        'title_en' => null,
        'description_ka' => 'სატესტო განცხადება GS Home-ისთვის.',
        'description_ru' => ($o['no_ru'] ?? false) ? null : "Тестовое объявление для экрана GS Home. Светлая квартира, {$rooms} комн., ремонт, рядом метро и парк.",
        'contact_name' => $o['name'] ?? 'Контакт '.$n,
        'contact_phone' => $o['phone'] ?? null,
        'has_viber' => (bool) mt_rand(0, 1), 'has_whatsapp' => (bool) mt_rand(0, 1),
        'is_owner' => $o['owner'] ?? false, 'is_agency' => $o['agency'] ?? false, 'agency_name' => $o['agency_name'] ?? null,
        'vip_status' => 'none', 'is_urgent' => false, 'status' => $o['status'] ?? 'active', 'view_count' => mt_rand(0, 500),
        'source_created_at' => $created, 'source_updated_at' => $created->copy()->addDays(mt_rand(0, 3)),
    ], $o['extra'] ?? []));
    foreach ($images->random(min(3, $images->count())) as $i => $img) {
        DB::table('listing_images')->insert(['listing_id' => $l->id, 'url' => $img->url, 'url_thumb' => $img->url_thumb,
            'is_main' => $i === 0, 'is_360' => false, 'order_no' => $i + 1, 'created_at' => now(), 'updated_at' => now(), 'source_url' => null]);
    }

    return $l;
};
$foreign = fn () => '5'.mt_rand(51, 99).mt_rand(100000, 999999);

// Наши: сотрудники Vake (продажа и сдача), агентские объявления
foreach ($vake['sale'] as $p) {
    $make(['deal' => 1, 'phone' => $national($p), 'name' => 'Агент Vake', 'agency' => true, 'agency_name' => 'GS Home Vake']);
}
foreach ($vake['rent'] as $p) {
    $make(['deal' => 2, 'phone' => $national($p), 'name' => 'Агент Vake', 'agency' => true, 'agency_name' => 'GS Home Vake']);
}
// Связанная компания (обмен включён): Saburtalo
foreach ($sab['sale'] as $p) {
    $make(['deal' => 1, 'phone' => '0'.$national($p), 'name' => 'Агент Saburtalo']);
}
foreach ($sab['rent'] as $p) {
    $make(['deal' => 2, 'phone' => '995'.$national($p), 'name' => 'Агент Saburtalo']);
}
// Номера офисов
$make(['deal' => 1, 'phone' => $national($vake['office']), 'name' => 'Офис Vake', 'agency' => true, 'agency_name' => 'GS Home Vake']);
$make(['deal' => 2, 'phone' => $national($sab['office']), 'name' => 'Офис Saburtalo', 'agency' => true, 'agency_name' => 'GS Home Saburtalo']);
// Чужие: собственники, агентства, частные агенты; часть без русского текста
for ($i = 0; $i < 6; $i++) {
    $make(['deal' => 1, 'phone' => $foreign(), 'owner' => true, 'name' => 'Собственник']);
}
for ($i = 0; $i < 5; $i++) {
    $make(['deal' => 2, 'phone' => $foreign(), 'owner' => true, 'name' => 'Собственник', 'no_ru' => $i < 2]);
}
for ($i = 0; $i < 3; $i++) {
    $make(['deal' => 1, 'phone' => $foreign(), 'agency' => true, 'agency_name' => $agencies[$i], 'name' => 'Менеджер']);
}
for ($i = 0; $i < 3; $i++) {
    $make(['deal' => 2, 'phone' => $foreign(), 'name' => 'Частный агент']);
}
// myhome: замаскированный телефон
for ($i = 0; $i < 3; $i++) {
    $make(['deal' => $i % 2 + 1, 'source' => 'myhome_ge', 'phone' => '5554'.mt_rand(10, 99).'***', 'owner' => true, 'name' => 'Собственник']);
}
// Группы дублей: собственник (главное) + копия нашего агента с теми же параметрами
$pairs = [[1, $vake['sale'][0]], [1, $sab['sale'][1]], [2, $vake['rent'][1]], [2, $sab['rent'][2]]];
foreach ($pairs as $k => [$deal, $ourPhone]) {
    $same = ['deal' => $deal, 'rooms' => 2 + $k % 2, 'area' => 58 + $k * 7, 'floor' => 3 + $k, 'floors' => 9, 'district' => 1 + $k];
    $make($same + ['phone' => $foreign(), 'owner' => true, 'name' => 'Собственник (дубль)']);
    $make($same + ['phone' => $national($ourPhone), 'name' => 'Наш агент (копия)', 'days' => 1]);
}
// Не должны попасть в GS Home: посуточно, залог, снятые
$make(['deal' => 3, 'phone' => $national($vake['rent'][0]), 'name' => 'Посуточно']);
$make(['deal' => 3, 'phone' => $foreign()]);
$make(['deal' => 4, 'phone' => $foreign()]);
$make(['deal' => 4, 'phone' => $national($vake['sale'][1])]);
$make(['deal' => 1, 'phone' => $national($vake['sale'][2]), 'status' => 'inactive']);
$make(['deal' => 2, 'phone' => $foreign(), 'status' => 'inactive']);

echo "created {$n}\n";
