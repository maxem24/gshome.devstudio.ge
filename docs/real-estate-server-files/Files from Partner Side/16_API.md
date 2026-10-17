---
tags: [real-estate, api]
---

# API листингов

**Соседи:** [[06_Поиск_и_гео]] · [[09_Деплой]] · [[05_Админка_Filament]] · [[15_Известные_ловушки]]

API для внешних систем (сейчас — вторая Laravel-система на том же сервере). Только чтение, кроме одной кнопки «Актуализировать» — перескачать объявление и дубли с сайтов ([[16_API_актуализация]]); тест `ApiRoutesAreReadOnlyTest`. Дизайн и решения — `docs/superpowers/specs/2026-10-09-listings-api-design.md`.

---

## Для разработчиков клиента

### Подключение

| | |
|---|---|
| Базовый URL | настройка клиента `REAL_ESTATE_API_URL`: публично `https://real-estate.devstudio.ge/api/v1`; на том же сервере — внутренний адрес (см. [[09_Деплой]]) |
| Ключ | `REAL_ESTATE_API_KEY` в `.env` клиента. Вид `re_live_…` |
| Заголовок | `Authorization: Bearer re_live_…` — **только** заголовок, ключ в query не принимается |
| Формат | JSON, UTF-8, даты ISO 8601 |

```php
// сторона клиента
$api = Http::baseUrl(config('services.real_estate.url'))
    ->withToken(config('services.real_estate.key'))
    ->acceptJson()->timeout(10)
    ->retry(2, 200, fn ($e) => $e instanceof ConnectionException || in_array($e->response?->status(), [500, 502, 504], true), throw: false);

$page = $api->get('/listings', ['city_id' => 1, 'deal_type_id' => '2', 'rooms' => '2,3', 'price_usd_max' => 800])->json();
```

### Эндпоинты

| Путь | Что |
|---|---|
| `GET /me` | клиент, имя ключа, `expires_at`, лимит в минуту, `refresh_per_minute`. Дёргать при старте и раз в сутки |
| `GET /listings` | список с фильтрами, курсорная пагинация |
| `GET /listings/{id}` | одно объявление (дубли кластера тоже доступны по id) |
| `POST /listings/{id}/refresh`, `GET /listing-refreshes/{id}` | кнопка «Актуализировать»: объявление и его дубли со всех сайтов вне очереди, асинхронно — [[16_API_актуализация]] |
| `GET /dictionaries/{name}` | `cities`, `districts?city_id=`, `subdistricts?district_id=`, `deal-types`, `property-types`, `amenities`, `subway-stations?city_id=` — `id`, `slug`, `title_ka/en/ru`; `conditions`, `building-statuses` — `slug`, `title_ka/en/ru` (фильтр принимает `slug`) |

### Фильтры `GET /listings`

Отдельная памятка для разработчика клиента с рецептами — [[16_API_фильтры]].

| Параметр | Пример | Смысл |
|---|---|---|
| `deal_type_id`, `property_type_id` | `1` или `1,3` | тип сделки / недвижимости (id из справочников) |
| `city_id`, `district_id`, `subdistrict_id` | `1`, `1,2` | локация |
| `price_usd_min/max`, `price_gel_min/max` | `price_usd_max=800` | цена |
| `area_min`, `area_max` | `60` | общая площадь, м² |
| `rooms` | `2`, `1,2`, `5+`, `1,5+` | комнаты (несколько «+» — берётся меньший) |
| `bedrooms`, `bathrooms_min` | `2`, `3+` / `2` | спальни (как `rooms`), санузлов не меньше |
| `floors_total_min`, `floors_total_max` | `5` | этажность дома |
| `price_per_sqm_usd_min/max`, `price_per_sqm_gel_min/max` | `1500` | цена за м² |
| `condition`, `building_status` | `newly_renovated,white_frame` / `new_building` | состояние и статус дома — коды из `dictionaries/conditions`, `building-statuses` (в карточке — `condition_slug`, `building_status_slug`); у myhome статуса дома нет. Строки источника → коды: `App\Support\ListingStates`; незнакомые значения ежедневно ищет `listings:check-states` (→ Bugsnag) |
| `seller`, `is_agency` | `owner,agency,other` / `1` | кто продаёт; `other` — не собственник и не агентство |
| `floor_min`, `floor_max`, `not_first_floor`, `not_last_floor` | `not_first_floor=1` | этаж |
| `amenities` | `1,6` | есть **все** перечисленные удобства |
| `is_owner` | `1` | только от собственника |
| `phones` | `+995599111111,555 12 34 56` (≤ 500) | группы дублей, где **у любого активного объявления** телефон из списка. Форматы `555…`, `0555…`, `995…`, `+995…` — один номер; замаскированные (`555402***`) ни с чем не совпадают и в списке не принимаются (422) |
| `exclude_phones` | то же | наоборот: группы, где **ни у одного** объявления нет номера из списка (замаскированные и пустые попадают). Для «только другие компании» |
| `source` | `myhome_ge` / `ss_ge` | источник |
| `q` | `ვაკე` (≥ 2 символа) | подстрока в заголовке/адресе, любой язык, без учёта регистра |
| `lat`, `lng`, `radius_m` | `41.7151`, `44.8271`, `1000` (≤ 50 000) | в радиусе; в ответе `distance_m`. Объявления без координат не попадают |
| `has_images` | `1` | только с фото |
| `updated_since` | `2026-10-01T00:00:00+04:00` | обновлённые после момента (запись объявления изменилась при перескачивании; пересборка кластеров с 13.10 `updated_at` двигает только тем, у кого сменился признак главного в группе; снятые объявления API не отдаёт вовсе — пропавшее из выдачи значит «снято или больше не главное») |
| `created_since`, `created_until` | `2026-10-01`, `2026-10-09` | появились в источнике (`source_created_at`) в интервале, границы включительно; `created_until` без времени — весь этот день. Время без смещения считается тбилисским; смещение (`+04:00`, `Z`) учитывается. Объявления без даты источника не попадают |
| `only_primary` | по умолчанию `1` | одно главное объявление на кластер дублей; `0` — все |
| `sort` | `-updated_at` (по умолчанию), `price_usd`, `-price_usd`, `area_total`, `-area_total`, `distance` (нужны `lat/lng`) | порядок. **При сортировке по цене/площади объявления без значения не выдаются** |
| `per_page` | `50` (≤ 100) | размер страницы |
| `cursor` | из `meta.next_cursor` | следующая страница; курсор действует только с той же `sort` (иначе 422) |
| `lang` | `ka` (по умолчанию), `en`, `ru` | язык названий справочных полей (и в карточке; иное — 422) |

**Телефоны и дубли.** Объявление агента часто не главное в группе (главное — собственника). Поэтому `phones`/`exclude_phones` проверяют всю группу, а отдаётся главное объявление; какие объявления группы совпали — `cluster.matched_listing_ids` (только при `phones`). Метку «в группе есть наш сотрудник» без фильтра считайте по `cluster.phones_e164`.

**Длина запроса.** `+` в номере кодируйте как `%2B` (иначе он станет пробелом). Шлите номера в компактном E.164 (`%2B995599111111`): 500 номеров ≈ 8 КБ. Cloudflare режет URL длиннее 16 КБ, поэтому `phones` и `exclude_phones` вместе держите в пределах ~1000 компактных номеров. Больше 500 в одном параметре — 422.

**Номера.** Грузинский номер — 9 цифр, первая 3–7, в любом виде: `555…`, `0555…`, `995…`, `+995…`, `+995 0555…`. Иностранный — только с `+`/`00`. Совпадение по группе: при `only_primary=0` возвращаются все активные объявления совпавших групп, включая не совпавшие (по `matched_listing_ids` видно какие совпали); у объявления без группы совпадение видно по `contact.phone_e164`.

Фильтры комбинируются через И. **Неизвестный параметр — 422** (опечатку не проглотим молча). Всегда только активные объявления.

### Ответ

```json
{
  "data": [{
    "id": 13, "source": "ss_ge", "source_url": "https://home.ss.ge/…", "external_id": "36769672",
    "status": "active", "is_primary": true,
    "cluster": {"id": 5, "listings_count": 3, "phones_e164": ["+995577000001", "+995599111111"], "matched_listing_ids": [41]},
    "deal_type": {"id": 1, "slug": "sale", "title": "იყიდება"}, "property_type": {…},
    "city": {"id": 1, "title": "თბილისი"}, "district": {…}, "subdistrict": {…},
    "address": "…", "lat": 41.71, "lng": 44.82, "distance_m": 830,
    "area_total": 65.0, "rooms": 2, "floor": 5, "floors_total": 9, …,
    "price": {"usd": 90000.0, "gel": 243000.0, "per_sqm_usd": 1384.6, "per_sqm_gel": 3738.5, "negotiable": false},
    "title": {"ka": "…", "en": "…", "ru": "…"}, "description": {"ka": "…", "en": null, "ru": null},
    "images": [{"url": "https://real-estate.devstudio.ge/storage/…webp", "url_thumb": "…", "is_main": true, "order": 1}],
    "amenities": [{"id": 1, "slug": "elevator", "title": "…"}],
    "subway_stations": [{"id": 3, "title": "…", "walk_minutes": 7}],
    "seller": {"is_owner": true, "is_agency": false, "agency_name": null},
    "contact": {"name": "…", "phone": "555402***", "phone_e164": null, "has_viber": false, "has_whatsapp": false},
    "source_created_at": "…", "source_updated_at": "…", "source_expires_at": "…", "updated_at": "…"
  }],
  "links": {"next": "…/listings?…&cursor=…"},
  "meta": {"per_page": 50, "next_cursor": "eyJ…", "prev_cursor": null, "path": "…"}
}
```

- Телефон у myhome замаскирован (`555402***`) — так отдаёт сам источник. `contact.phone_e164` — номер в E.164 для сравнения строк, `null` у замаскированных и пустых.
- `cluster` — группа дублей (`null`, если объявление не кластеризуется — не хватает полей сделки/типа/города/комнат/этажа/площади): `phones_e164` — номера всех активных объявлений группы, `matched_listing_ids` — при фильтре `phones`. В карточке `GET /listings/{id}` ещё `cluster.listings[]` — остальные активные объявления группы: `id`, `source`, `source_url`, `is_primary`, `seller`, `contact` (с `phone_e164`), `price`, `updated_at`.
- Фото — по нашим ссылкам (`/storage/…`).
- `source_created_at` / `source_updated_at` — время источника по Тбилиси (`+04:00`).
- `source_expires_at` — когда источник сам снимет объявление (ss.ge `endDate`, по Тбилиси); `null` — неизвестно (у myhome и у ss.ge, скачанных до 12.10, пока не перескачаны).
- Общего количества нет (дорого на сотнях тысяч строк); листайте по `next_cursor`, пока он не `null`.
- Ответы с `ETag` — повтор с `If-None-Match` даёт `304` без тела.

### Ошибки

`{"error": {"code": "…", "message": "…"}}`, для 422 ещё `fields`.

| HTTP | `code` | Что делать клиенту |
|---|---|---|
| 401 | `token_missing`, `token_invalid`, `token_expired`, `token_revoked` | не повторять; сообщить администратору — нужен новый ключ |
| 403 | `client_disabled`, `ip_not_allowed` | доступ выключен / запрос не с разрешённого IP |
| 404 | `not_found` | объявления нет или оно снято |
| 405 | `method_not_allowed` | API только читает (кроме `POST /listings/{id}/refresh`) |
| 422 | `validation_failed` | баг в запросе клиента — в лог |
| 503 | `query_timeout`, `unavailable` | запрос слишком тяжёлый (сузьте фильтры, не повторяйте тот же) / техобслуживание |
| 429 | `rate_limited` | ждать `Retry-After` секунд; заголовки `X-RateLimit-Limit/Remaining`. Также после 30 неудачных попыток ключа с одного IP в минуту |
| 500 | `server_error` | повторить позже |

### Лимит запросов

По умолчанию 60 в минуту на клиента. Для «живых» клиентов, которые ходят в API на каждый экран пользователя (GS Home), — ~600; задаётся в админке у клиента.

### Срок ключа

Ключи — на 30 / 90 дней, 1 год или 5 лет. За 14 и 3 дня до истечения на email клиента приходит письмо (если день пропущен — придёт в следующий; заменённому при продлении ключу — нет); срок также в `GET /me`. Продление — новый ключ, старый работает ещё 7 дней (не дольше своего срока).

---

## Для нас

### Где что

| | |
|---|---|
| Маршруты | `routes/api.php` (префикс `api/v1`, middleware `api.log → api.key → api.client → throttle:api → api.timeout → api.etag`) |
| Ключи | Sanctum `personal_access_tokens` (только хеш; `revoked_at`, `created_by`, `revoked_by`, `replaces_token_id`), владелец — `App\Models\ApiClient` |
| Выпуск / продление / отзыв | `App\Services\Api\ApiKeyService`; в админке — «Система → API-клиенты» → клиент → «Ключи» |
| Аутентификация | `App\Http\Middleware\Api\AuthenticateApiKey` (точные коды 401), `EnsureClientAllowed` (активность, IP/CIDR) |
| Фильтры | `App\Http\Requests\Api\ListingIndexRequest` (белый список) → `App\Services\Api\ListingQuery` |
| Ответ | `App\Http\Resources\Api\V1\ListingResource` |
| Справочники | `App\Http\Controllers\Api\V1\DictionaryController`, кеш 1 ч, сброс при изменении справочной модели (`FlushesApiDictionaryCache`) |
| Лимит | `RateLimiter::for('api')` в `AppServiceProvider`, `api_clients.rate_limit_per_minute`; нажатия «Актуализировать» — `RateLimiter::for('api-refresh')`, `api_clients.refresh_per_minute` |
| Журнал | `api_request_logs` (параметры, статус, время, IP; без ключа и ответа), чистка `model:prune` ежедневно, хранение 30 дней |
| Напоминания | `php artisan api:remind-expiring` ежедневно 09:00 |
| Настройки | `config/api.php`; IP Cloudflare — `App\Support\CloudflareIps` |

### Выдать доступ новой системе

1. «API-клиенты» → создать: название, email для напоминаний, лимит, разрешённые IP (клиент на этом же сервере — `127.0.0.1`).
2. На странице клиента → «Ключи» → «Выпустить ключ» → срок → **скопировать ключ из окна** (второй раз его не показать).
3. Передать ключ по защищённому каналу, не в чате/почте открытым текстом.

Отключить доступ целиком — снять «Доступ включён» у клиента; отозвать один ключ — «Отозвать».
