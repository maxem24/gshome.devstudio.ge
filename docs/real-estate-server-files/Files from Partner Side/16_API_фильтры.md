---
tags: [real-estate, api]
---

# API: фильтры на стороне сервера

**Соседи:** [[16_API]] · [[16_API_актуализация]]

Документ для разработчика системы-клиента. Подключение, ключ и формат карточки — в [[16_API]].

---

## Главное

**Фильтруйте запросом, а не у себя.** `GET /listings` отбирает объявления на сервере по любому
сочетанию параметров ниже, и результат уже отсортирован. Не нужно листать курсором всю базу (~90 тыс.
объявлений) и отбирать нужное у себя: это сотни запросов вместо одного, а число запросов в минуту
у клиента ограничено (`GET /me` → `rate_limit_per_minute`).

- Параметры комбинируются через **И**; внутри одного параметра значения через запятую — **ИЛИ**
  (`rooms=2,3` — двух- или трёхкомнатные).
- **Неизвестный параметр — 422**, а не «всё подряд»: опечатку увидите сразу. В ответе
  `error.fields` назовёт параметр.
- Отдаются только активные объявления, по одному главному на группу дублей (`only_primary=0` — все).
- Курсор (`next_cursor`) нужен только чтобы листать уже отфильтрованный результат.
- Пустое значение (`seller=`, `condition=`) — фильтр не применяется. Целые параметры
  (`bathrooms_min`, `floor_min`, `rooms`) принимают только целые: `2.0` — 422.
- `+` в `rooms=5+` кодируйте как `%2B`, иначе он станет пробелом.

## Все фильтры

| Параметр | Пример | Что отбирает |
|---|---|---|
| `deal_type_id` | `1`, `1,2` | тип сделки (`GET /dictionaries/deal-types`) |
| `property_type_id` | `1`, `1,3` | тип недвижимости — квартира, дом… (`/dictionaries/property-types`) |
| `city_id`, `district_id`, `subdistrict_id` | `1`, `4,7` | город, район, подрайон (`/dictionaries/cities`, `districts?city_id=`, `subdistricts?district_id=`) |
| `price_usd_min`, `price_usd_max` | `price_usd_max=1000` | цена в долларах |
| `price_gel_min`, `price_gel_max` | `200000` | цена в лари |
| `price_per_sqm_usd_min/max`, `price_per_sqm_gel_min/max` | `price_per_sqm_usd_max=1500` | цена за м² |
| `area_min`, `area_max` | `60` | общая площадь, м² |
| `rooms` | `2`, `1,2`, `5+`, `1,5+` | комнаты; `5+` — от пяти |
| `bedrooms` | `2`, `3+` | спальни; так же, как `rooms` |
| `bathrooms_min` | `2` | санузлов не меньше |
| `floor_min`, `floor_max` | `3`, `10` | этаж |
| `not_first_floor`, `not_last_floor` | `1` | не первый / не последний этаж |
| `floors_total_min`, `floors_total_max` | `5`, `16` | этажность дома |
| `condition` | `newly_renovated,white_frame` | состояние квартиры, коды — `/dictionaries/conditions` |
| `building_status` | `new_building` | статус дома, коды — `/dictionaries/building-statuses` |
| `amenities` | `1,6` | есть **все** перечисленные удобства (`/dictionaries/amenities`) |
| `seller` | `owner`, `agency`, `other`, `owner,other` | кто продаёт: собственник, агентство, другие (частный риелтор и т.п.) |
| `is_owner` | `1` / `0` | только от собственника / кроме собственников |
| `is_agency` | `1` / `0` | только агентства / кроме агентств |
| `phones`, `exclude_phones` | `%2B995599111111` | группы дублей с номером из списка / без него — подробно в [[16_API]] |
| `source` | `ss_ge`, `myhome_ge` | сайт-источник |
| `q` | `ვაკე` (от 2 символов) | подстрока в заголовке или адресе, любой язык |
| `lat`, `lng`, `radius_m` | `41.7151`, `44.8271`, `1000` | в радиусе (до 50 км); в ответе `distance_m` |
| `has_images` | `1` | только с фото |
| `created_since`, `created_until` | `2026-10-01`, `2026-10-09` | появились на сайте в интервале (по Тбилиси) |
| `updated_since` | `2026-10-14T00:00:00+04:00` | изменились у нас после момента — для синхронизации |
| `only_primary` | `1` (по умолчанию) / `0` | одно на группу дублей / все |
| `sort` | `-updated_at` (по умолчанию), `price_usd`, `-price_usd`, `area_total`, `-area_total`, `distance` | порядок; при сортировке по цене/площади объявления без значения не выдаются |
| `per_page` | `50` (до 100) | размер страницы |
| `cursor` | из `meta.next_cursor` | следующая страница той же выборки |
| `lang` | `ka`, `en`, `ru` | язык названий в ответе |

### Справочники

Id для фильтров берите из справочников, а не зашивайте в код: `GET /dictionaries/{name}`, кешируйте
у себя на сутки.

| Справочник | Поля |
|---|---|
| `cities`, `districts?city_id=`, `subdistricts?district_id=`, `deal-types`, `property-types`, `amenities`, `subway-stations?city_id=` | `id`, `slug`, `title_ka/en/ru` |
| `conditions`, `building-statuses` | `slug` (его и передавайте в фильтр), `title_ka/en/ru` |

Состояние (`condition`): `newly_renovated` — новый ремонт, `renovated` — с ремонтом, `old_renovation` —
старый ремонт, `renovation_in_progress` — идёт ремонт, `needs_renovation` — требует ремонта,
`white_frame` / `green_frame` / `black_frame` — белый / зелёный / чёрный каркас.
Статус дома (`building_status`): `new_building` — новостройка, `old_building` — старый фонд,
`under_construction` — строится. **У myhome.ge статуса дома нет** (сайт его не отдаёт): фильтр
`building_status` объявления myhome не находит. Объявления без состояния (~5%) не попадают под
`condition`. В карточке рядом с текстом источника (`condition`, `building_status`)
приходят эти же коды: `condition_slug`, `building_status_slug`.

## Рецепты

**Район по названию → id.** Один раз: `GET /dictionaries/districts?city_id=1` → найти «Ваке» в
`title_ru` → `id`. Дальше фильтр `district_id=<id>`.

**Двушки в аренду в Ваке до $1000 от собственника:**

```
GET /listings?deal_type_id=<аренда>&property_type_id=<квартира>&district_id=<Ваке>&rooms=2&price_usd_max=1000&seller=owner&lang=ru
```

**Новостройки с ремонтом, 60–90 м², не первый этаж, от $1200 до $1800 за м²:**

```
GET /listings?building_status=new_building&condition=newly_renovated,renovated&area_min=60&area_max=90&not_first_floor=1&price_per_sqm_usd_min=1200&price_per_sqm_usd_max=1800
```

**Всё, кроме агентств, в радиусе километра от точки, ближайшие первыми:**

```
GET /listings?is_agency=0&lat=41.7151&lng=44.8271&radius_m=1000&sort=distance
```

**Свежее за сегодня** (для ленты «новые»): `created_since=2026-10-14&sort=-updated_at`.

**Синхронизация своей копии** (если она нужна): раз в N минут `updated_since=<время прошлого
запроса>` с теми же фильтрами, листая курсором только этот хвост.

## Пример (Laravel, сторона клиента)

```php
$filters = array_filter([
    'deal_type_id'  => $form->dealTypeId,
    'district_id'   => implode(',', $form->districtIds),
    'rooms'         => implode(',', $form->rooms),          // ['1', '2', '4+']
    'price_usd_max' => $form->maxPrice,
    'seller'        => $form->onlyOwners ? 'owner' : null,
    'condition'     => implode(',', $form->conditions),     // коды из /dictionaries/conditions
    'per_page'      => 50,
    'lang'          => app()->getLocale(),
], fn ($v) => $v !== null && $v !== '');

$page = $api->get('/listings', $filters + ['cursor' => $cursor])->throw()->json();
// $page['data'] — уже отобранные объявления; $page['meta']['next_cursor'] — следующая страница
```

Пустые значения лучше не передавать вовсе (`array_filter`, как выше): так в журнале запросов видно, какие фильтры реально выбраны.
