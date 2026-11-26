# Публикация объекта на MyHome и ss.ge из GS Home — дизайн

Дата: 26.11.2026. Ветка для работы — от `main` (документы разведки лежат в `docs/ssge-api-research`).
План реализации — `docs/superpowers/plans/2026-11-26-portal-publishing.md`. Сырые снятые запросы —
[[14_API_листингов_myhome]] и [[15_API_ss.ge]]. Эта спецификация — их свод и проект того, как
подключить это к CRM. **Разработка не начата.**

## Зачем

Требование владельца проекта (26.11): когда агент **берёт объект в работу** (`Pins::take`, кнопка
«Взять в работу» на карточке), у него появляется кнопка **«Опубликовать на MyHome и ss.ge»**.
По нажатию GS Home сам отправляет на оба портала запросы публикации, но **с контактами агента вместо
контактов владельца**: имя и телефон агента, а не собственника. Цена, описание и фото берутся из
объявления Real Estate, но агент может их поправить перед отправкой.

Позже по тому же каналу — править опубликованное, снимать, продлевать (раздел «Этапы»).

## Что мы уже умеем (проверено живыми запросами 19–26.11.2026)

Все проверки — под одним аккаунтом `jshome926@gmail.com` (на MyHome `user_id 9224739`, тип `agent`;
на ss.ge брокер, компания `9255`). Портальные API **внутренние, без официальной документации**: ими
пользуются сами сайты.

| действие | MyHome (`api-statements.tnet.ge`) | ss.ge (`api-gateway.ss.ge`) |
|---|---|---|
| вход без браузера | ✅ один запрос `POST accounts.tnet.ge/api/ka/user/auth`, токен ~11 мин | ✅ цепочка из 5 запросов (NextAuth + IdentityServer4), токен 60 мин |
| загрузка фото | ✅ `POST static-api-statements.tnet.ge/v1/files/upload-image` (multipart, поле `file`); ответ не снят | ✅ `POST /v1/RealEstate/upload-image` (JSON, base64), ответ снят (Max, 25.11) |
| создание и публикация | ✅ `POST /v1/statements/create` (multipart); пользователь вручную 19.11, HTTP 200, объявление появилось | ✅ `create-draft` → `PaidService/create-application`; объявление `37478307` опубликовано нами, статус Active |
| правка опубликованного | ✅ `PUT /v1/statements/update/{id}` (JSON, полный объект): снято из браузера и повторено из скрипта; комнаты 2 → 3 → 2, применилось сразу | ✅ `GET get-edit-application?…&isEdit=true` → `POST create-edit-application` с `"isEdit": true`: комнаты 3 ↔ 4 четырьмя запросами, сразу, без модерации |
| снятие | ❌ эндпоинт не снят | ✅ `POST change-application-status` `{status: 3}` (мягко) |
| телефон агента | ❓ не проверено, можно ли не-аккаунтный номер | ❓ только подтверждённые номера аккаунта |
| модерация | не видна на правке комнат | не видна на создании и на правке комнат |

Скрипты в `~/.cursor/script-library/` (в репозиторий не входят, служат образцом):
`myhome-bearer-token`, `myhome-statement-update`, `ss-ge-bearer-token`, `ss-ge-create-listing`.

## Как это работает на самом деле

### MyHome

**Вход.** `POST https://accounts.tnet.ge/api/ka/user/auth`, JSON
`{"Email": "…", "Password": "…", "token": "", "Continue": "https://www.myhome.ge/"}` → 200,
`data.access_token` (JWT, 660 с), `data.refresh_token` (~120 дней), `data.Data` (профиль). Поле
`token` — ответ invisible reCAPTCHA; пустое сейчас принимается, но может перестать. Куки не ставятся.
Cloudflare отвечает `403 Error 1010` на стандартный User-Agent Python/cURL — нужен браузерный.

**Заголовки всех запросов к `api-statements.tnet.ge`:** `Global-Authorization: <access_token>`,
`x-website-key: myhome`, `x-referrer-key: myhome`, `locale: ka`, `Accept: application/json`,
`Origin: https://statements.myhome.ge`, `Referer: https://statements.myhome.ge/`.

**Создание** — `POST /v1/statements/create`, `multipart/form-data`. Поля (рабочий пример 19.11):

| группа | поля |
|---|---|
| тип | `real_estate_type_id` (1 квартира), `deal_type_id` (1 продажа, 2 аренда), `rent_period`, `duration_id` (1 = 30 дней), `websites[0]=2` (MyHome) |
| место | `city_id` (1 Тбилиси), `district_id`, `urban_id`, `street_id`, `location_id`, `longitude`, `latitude`, `rs_code`, `appear_rs_code` |
| дом | `status_id` (1 старое, 2 новое, 3 строится), `project_type_id` (8 нестандартный), `condition_id` (1–8), `floor`, `total_floors` |
| комнаты | `room_type_id` (число комнат), `bedroom_type_id`, `living_room_*`, `loggia_area`, `porch_area`, `storeroom_*` |
| площадь | `area`, `area_type_id` (1 = м²) |
| цена | `price_type_id` (3 в обоих снятых образцах), `total_price`, `square_price`, `currency_id` (1 GEL, 2 USD, 3 EUR) |
| контакт | `phone_number` (9 цифр, без `+995`), `is_phone_number_visible`, `ka/en/ru[owner_name]` |
| тексты | `ka/en/ru[comment]`, `ka/en/ru[address]`, `ka/en/ru[street_number]` |
| медиа | `images[n][image_id]` и `images[n][url]` из ответа upload, `youtube_link`, `3d_url` |

Заголовок объявления сайт строит сам (`GET /v1/statements/title-preview?deal_type_id=…&real_estate_type_id=…&city_id=…&status_id=…&urban_id=…&room_type_id=…`).
Задать его нельзя, текст идёт в `ka[comment]`.

**Фото** — `POST https://static-api-statements.tnet.ge/v1/files/upload-image`, `multipart`, поле `file`;
заголовки как выше плюс `Origin: https://statements.myhome.ge`. Результат — `image_id` и `url`
(`https://static-api-statements.tnet.ge/uploads/YYYYMM/YYYYMMDD/statements/<hash>.webp`).
Форму ответа надо снять (спайк).

**Правка** — `PUT /v1/statements/update/{id}`, JSON; тело — **полный объект**, а не изменения. Снято из
браузера (reqid 711 и 915), 57 ключей:

```json
{"real_estate_type_id":1,"deal_type_id":2,"rent_period":null,"rent_type_id":null,"city_id":1,
 "street_id":"3697","location_id":201,"district_id":1,"urban_id":7,"metro_station_id":null,
 "rs_code":"","appear_rs_code":false,"longitude":44.802558,"latitude":41.792245,"duration_id":1,
 "parameters":[2,3,1,21,26,11,50,25,47,24,10,43,44,45,46,4,16,18,20,19,17,6],
 "status_id":1,"build_year_id":null,"project_type_id":"8","room_type_id":"3","bedroom_type_id":null,
 "bathroom_type_id":null,"floor":"1","total_floors":"5","height":null,"balconies":null,
 "balcony_area":null,"heating_type_id":null,"parking_type_id":null,"hot_water_type_id":null,
 "material_type_id":null,"condition_id":1,"living_room_area":"","loggia_area":"","porch_area":"",
 "waiting_space_area":"","storeroom_area":"","living_room_type_id":"","storeroom_type_id":"",
 "door_window_type_id":"","area":60,"area_type_id":1,"price_type_id":3,"total_price":1300,
 "square_price":21.67,"currency_id":1,"phone_number":"544407436","is_phone_number_visible":true,
 "youtube_link":"","3d_url":"",
 "ka":{"comment":"…","address":"…","owner_name":"…","street_number":"0"},"en":{…},"ru":{…},
 "images":[{"image_id":113105541,"url":"https://static-api-statements.tnet.ge/uploads/…webp"}],
 "hidden_owner_name":"","hidden_owner_phone_number":"","hidden_owner_comment":""}
```

Ловушки правки: форма сама ставит `bedroom_type_id: null` при смене комнат, поэтому спальни надо
отправлять явно; поле `last_updated_by_website` не меняется даже при реальной правке; сохранение без
изменений — no-op. Текущие данные — `GET /v1/statements/edit/{id}` → `data.statement`.

**Известные справочники.** `parameters` (удобства): `1` gas, `2` internet, `3` tv, `4` conditioner,
`10` furniture, `11` telephone, `16` refrigerator, `17` washing_machine, `18` dishwasher, `19` stove,
`20` oven, `21` living_room, `24` water, `25` sewerage, `26` electricity, `43` bed, `44` sofa,
`45` table, `46` chairs, `47` kitchen, `50` storeroom (полный список — `GET /v1/statements/statement-parameters`).
Валюты `1` GEL / `2` USD / `3` EUR. Города — `GET /v1/locations/cities` и
`api-locations.tnet.ge/v2/cities`; подсказки районов — `api-locations.tnet.ge/v2/suggestions?with_visible_in_cities=1&location_id=…`.

**Срок жизни.** Объявление живёт 30 дней (`end_date_by_website` = создание + 30 суток),
продлевается кнопкой «обновить» (`can_refresh_by_website`).

### ss.ge

**Вход** — пять запросов, воспроизводим вход сайта (`home.ss.ge` + `account.ss.ge`): `GET /api/auth/csrf` →
`POST /api/auth/signin/identity-server4` → `POST account.ss.ge/ka/account/Login` (форма `userName`,
`password`, `returnUrl` из адреса страницы входа; ответ JSON `{redirectUrl}`) → `GET <redirectUrl>` →
`GET /api/auth/session` → `accessToken` (JWT, 60 мин). Прямой `password` grant закрыт (`invalid_client`).
Цепочка хрупкая: страница входа разбирается регулярками; SMS / TOTP / капча на входе её сломают.

**Заголовок:** `Authorization: Bearer <accessToken>`. Браузерный User-Agent обязателен.

**Цикл публикации:**

1. `GET /v1/RealEstate/get-draft` — **слот черновика один на аккаунт**. Если он занят, `create-draft` с
   `id: 0` вернёт тот же `applicationId` и перезапишет чужой черновик. Перед созданием проверять слот:
   свободен — ответ **500 «Draft application not found»** (это норма, не авария), занят — 200 с телом черновика.
2. `POST /v1/RealEstate/create-draft` — тело (плоское):

```json
{"realEstateTypeId":5,"realEstateDealTypeId":1,"cityId":95,"currencyId":1,"showSiteCurrencyId":1,
 "priceType":1,"price":900,"priceUsd":330.5,"unitPrice":12,"unitPriceUsd":4.4,
 "phoneNumbers":[{"hasViber":false,"hasWhatsapp":false,"isApproved":true,"isMain":false,"phoneNumber":"544407436"}],
 "contactPerson":"<имя>","messagingOn":true,"phoneCallsOn":true,
 "locationLatitude":41.72,"locationLongitude":44.76,"subdistrictId":9,"streetId":207,"streetNumber":"49",
 "rooms":3,"bedrooms":1,"totalArea":75,"floor":5,"floors":9,"status":453,"state":16,
 "descriptionGe":"<текст>","moderationBlockCategories":null,"realEstateApplicationId":0}
```

   Ответ: `{"success":true,"applicationId":37478307}`.
3. `POST /v1/RealEstate/upload-image` — `{"applicationId": N, "content": "data:image/jpeg;base64,…"}` →
   `{"isSuccess":true,"imageId":…,"fileName":"20261125/4_<uuid>.jpg","fileUrl":"…"}`. Дальше фото кладут в
   `images`: `{"applicationImageId": …, "fileName": "…", "isMain": true, "is360": false, "orderNo": 0, "imageRotation": 0}`.
   Без фото публикация проходит (`images: []`, проверено).
4. `POST /v1/PaidService/create-application`:

```json
{"application": {<тело черновика>, "images": [], "realEstateApplicationId": 37478307, "userType": "Broker"},
 "paidServices": {"source":"Create Application","sourceDevice":"Mac OS/Chrome","isCreate":true,
   "items":[{"applicationId":37478307,"rubric":"RealEstate","realEstateDealTypeId":1,"cityId":95,"paidServices":[]}],
   "purchaseOptions":{"flow":1}}}
```

   `paidServices: []` — бесплатный «стандарт». В ответе `application.success`, `detailsUrl`, `title`.
   Перед публикацией сайт спрашивает дубли: `POST /v1/RealEstate/user-own-similars` и `similars-exists`.

**Правка** — `GET /v1/RealEstate/get-edit-application?applicationId=N&isEdit=true`, затем
`POST /v1/RealEstate/create-edit-application` с тем же телом плюс `"isEdit": true`. **Без `isEdit` правка
остаётся «черновиком правки» (статус 6) и снимает объявление с выдачи.** Тело можно короткое
(основные поля + `isEdit`) или «как у сайта» (+ `userId`, `isModerator:false`, `applicationStatus:0`,
булевы удобства, `videos:[]`, `park/pharmacy/school/supermarket`).

**Снятие** — `POST /v1/RealEstate/change-application-status`
`{"applicationId":[N],"blockReason":"…","isModeration":true,"status":3}`, ответ пустой, статус 3 = удалённое.

**Справочники.** `realEstateTypeId` 5 = квартира; `realEstateDealTypeId` 1 = аренда помесячно (id для
продажи не снят); город Тбилиси `95`; Варкетили `subdistrictId 9`; улицы I `207`, II `196`, III `782`,
IV `198`, ферма `1228`; здание `status` 453 = старое; ремонт `state` 16 = новый. Справочники по пути
**без слэша**: `GET /v1/RealEstate/DistricsWithSubDistricts95`, `…/Location<keyword>`.

### Сравнение

| | MyHome | ss.ge |
|---|---|---|
| авторизация | 1 запрос, токен 11 мин | 5 запросов со скрейпингом, токен 60 мин |
| публикация | 1 запрос после фото | 3 запроса: черновик → фото → публикация |
| фото | multipart, до публикации | base64, **после** создания черновика (нужен `applicationId`) |
| правка | `PUT` полным телом | `POST` с `isEdit: true` |
| слот | параллельность не ограничена (не проверено) | **один черновик на аккаунт** |
| заголовок | генерирует сайт | генерирует сайт |
| срок | 30 дней | не снят |

## Откуда берём данные: Real Estate → портал

Исходник — `App\RealEstate\Listing` (из API Real Estate, только чтение). Поля, которых для публикации
не хватает, остаются пустыми; агент дополняет их в окне перед отправкой.

| `Listing` | MyHome | ss.ge | примечание |
|---|---|---|---|
| `dealTypeSlug` `sale` / `rent` | `deal_type_id` 1 / 2 | `realEstateDealTypeId` (аренда 1; продажа — снять) | |
| `propertyType` | `real_estate_type_id` (1 квартира) | `realEstateTypeId` (5 квартира) | пока только квартиры |
| `city` / `district` / `subdistrictId` | `city_id`, `district_id`, `urban_id` | `cityId`, `subdistrictId` | таблицы соответствия, см. ниже |
| `address` | `ka/en/ru[address]`, `street_id`, `ka/en/ru[street_number]` | `streetId`, `streetNumber` | улицу ищем по справочнику портала |
| `lat`, `lng` | `latitude`, `longitude` | `locationLatitude`, `locationLongitude` | |
| `areaTotal` | `area`, `area_type_id=1` | `totalArea` | |
| `rooms` | `room_type_id` | `rooms` | |
| `bedrooms` | `bedroom_type_id` | `bedrooms` | MyHome сбрасывает при смене комнат — задавать явно |
| `floor`, `floorsTotal` | `floor`, `total_floors` | `floor`, `floors` | |
| `condition` (slug) | `condition_id` (1–8) | `state` (16 новый ремонт, …) | таблица |
| `buildingStatus` (slug) | `status_id` (1/2/3) | `status` (453 старое, …) | таблица |
| `priceUsd`, `priceGel` | `total_price`, `square_price`, `currency_id`, `price_type_id` | `price`, `priceUsd`, `unitPrice`, `unitPriceUsd`, `currencyId`, `priceType` | валюту берём GEL, как на рынке |
| `description` | `ka[comment]` (+ en/ru) | `descriptionGe` | телефоны собственника вырезаются |
| `images` | upload → `images[n]` | upload → `images[]` | скачиваем из Real Estate, перекодируем |
| `amenities` (slug) | `parameters[]` (id удобств) | булевы поля (`airConditioning`, `balcony`, …) | таблица |
| контакт агента | `phone_number`, `ka/en/ru[owner_name]` | `phoneNumbers[]`, `contactPerson` | **не** `Listing::contactPhone` |

**Таблицы соответствия.** Статические справочники (тип, сделка, состояние, статус дома, удобства) —
в `config/portals/*.php`. Районы, микрорайоны и улицы — через поиск по справочникам порталов
(`api-locations.tnet.ge/v2/suggestions`, `GET /v1/RealEstate/Location<keyword>`) с кешем на сутки;
если однозначно не нашли — окно просит агента выбрать. Проект Real Estate не лежит на этой машине;
его `SourceMappingSeeder` (соответствие id источников) надо сверить при реализации, там часть
обратных соответствий уже есть.

## Контакты агента вместо владельца

- **Имя** — `User::name` агента, взявшего объект (`ListingPin::holder`). **Телефон** — номер из `phones`,
  где `user_id` = этот агент. Несколько номеров — агент выбирает в окне.
- **Не копируем:** `Listing::contactName`, `contactPhone`, `contactPhoneE164`, `clusterPhonesE164`, `clusterListings[].phone_e164` и любые номера из текста.
  Из описания вырезаются номера (`+995…`, `5xx xxx xxx`, `5xxxxxxxx`) — перед отправкой агент видит
  результат и может править.
- Формат номера: 9 цифр без `+995` на обоих порталах (`PhoneNumber` хранит E.164 — обрезаем).
- **Главное ограничение:** ss.ge принимает в объявление только **подтверждённые номера аккаунта**
  (`GET /v1/Account/user-approved-phone-number-list-with-phone-check`); у MyHome есть проверка
  `GET /v1/users/validation/check?number=…`. Подойдёт ли номер агента, не проверено. Это определяет
  схему аккаунтов — см. «Открытые вопросы», пункт 1.

## Проект в GS Home

### Что видит и делает агент

1. Агент открывает карточку объекта и нажимает «Взять в работу» (уже есть). Появляется панель
   действий объекта; в ней новая кнопка **«Опубликовать на порталах»**.
2. Видна только если `pin()` активен и `Pins::canManage($pin, $viewer)` — то есть держателю и тем, кто
   вправе действовать за него (`OrgAccess`). Права — только через `OrgAccess`, направление — как у пина.
3. Окно подтверждения: порталы (две галочки, по умолчанию отмечен тот, на котором **нет** копии агента),
   цена, описание (с вырезанными номерами), фото (порядок, исключить), имя и телефон агента, предупреждения
   (нет улицы, нет цены, копия агента уже есть, портал не отвечает).
4. «Отправить» создаёт запись по каждому порталу и ставит джобу в очередь. Окно закрывается сразу.
5. На карточке секция **«Опубликовано»**: по порталу чип «в очереди / публикуется / опубликовано / ошибка»,
   ссылка на объявление, время, кто публиковал. Ошибка показывается с причиной и кнопкой «Повторить».
6. Результат приходит в колокольчик (`Notification`, как у `ListingImported`).

### Компоненты

| что | где (пространство `App\Portals`) |
|---|---|
| портал, типы | `Portal` (enum `myhome`, `ssge`), `PortalClient` (интерфейс) |
| данные к публикации | `ListingDraft` (DTO, нормализованное объявление), `DraftBuilder` (`Listing` + агент → `ListingDraft`), `PhoneScrubber` |
| соответствия | `config/portals/myhome.php`, `ssge.php`, `LocationResolver` |
| клиент MyHome | `MyHome\MyHomeAuth` (вход + кеш токена), `MyHome\MyHomeClient` (upload, create, update, health), `MyHome\MyHomeMapper` (`ListingDraft` → поля формы / JSON) |
| клиент ss.ge | `SsGe\SsGeAuth` (цепочка входа + кеш токена), `SsGe\SsGeClient` (draft, upload, publish, edit, delete), `SsGe\SsGeMapper` |
| сервис | `Publications` (старт, смена статуса, журнал, права), `PortalException` (+ подтипы `PortalAuthFailed`, `PortalRejected`, `PortalUnavailable`) |
| джоба | `App\Jobs\PublishToPortal` (очередь `portals`, один воркер, замок на аккаунт) |
| уведомления | `App\Notifications\PortalPublished`, `PortalPublishFailed` |
| экран | `ListingView`: действие `publishToPortals`, секция «Опубликовано» (partial) |

### Данные

Таблица `portal_publications` (мягкое удаление — «ничего не удаляется»):

| колонка | тип | зачем |
|---|---|---|
| `id` | bigint | |
| `listing_pin_id` | FK `listing_pins` | публикация привязана к объекту в работе |
| `user_id` | FK `users` | кто нажал |
| `portal` | varchar | `myhome` / `ssge` |
| `status` | varchar | `queued`, `publishing`, `published`, `failed`, `removed` |
| `external_id` | varchar null | id на портале (`26671397`, `37478307`) |
| `external_url` | varchar null | ссылка |
| `contact_name`, `contact_phone` | varchar | что ушло как контакт |
| `payload` | jsonb | что отправили (без токенов и паролей) |
| `response` | jsonb null | что ответил портал (усечённо) |
| `error` | text null | причина |
| `published_at`, `expires_at` | timestampTz null | `expires_at` для MyHome (+30 дней) |
| `created_at`, `updated_at`, `deleted_at` | | |

Частичный уникальный индекс `portal_publications_active` на `(listing_pin_id, portal)` при
`status IN ('queued','publishing','published') AND deleted_at IS NULL` — две публикации одного объекта на
один портал одновременно база не пропустит (по образцу `listing_pins_active_key`).

### Очередь и порядок

- Отдельная очередь `portals` с **одним** воркером (supervisor в `config/horizon.php`): у ss.ge один
  слот черновика на аккаунт, параллельные публикации перезаписали бы друг друга. Дополнительно —
  `WithoutOverlapping("portal:{portal}")` с `releaseAfter`.
- Порядок ss.ge: проверить `get-draft` → `create-draft` → `upload-image` × N → `create-application`.
  Любой сбой после создания черновика — `delete-draft`, чтобы слот не остался занят.
- Порядок MyHome: `upload-image` × N → `create`.
- Токены не храним в базе: кеш Redis с TTL чуть меньше срока жизни (MyHome 9 мин, ss.ge 55 мин), при
  401 — один повторный вход.
- Попыток джобы 1 (`tries = 1`): публикация не идемпотентна, повтор делает человек кнопкой.
  Перед повтором джоба проверяет, не создано ли объявление (по `external_id`).

### Секреты и безопасность

- Логины и пароли порталов — `.env` (`MYHOME_USERNAME`, `MYHOME_PASSWORD`, `SS_USERNAME`, `SS_PASSWORD`),
  в коде — `config('services.myhome|ssge')`. В git не попадают. В `.env.example` — только имена.
- В логи, Bugsnag и `payload` не пишем заголовки `Authorization` / `Global-Authorization`, поля
  `password`, `AccessToken`, `RefreshToken`. Проверить `filters` в `config/bugsnag.php`.
- Телефон агента в `payload` хранится (это контакт на публичном объявлении), номера собственника — нет.
- Права: публикует только тот, кто `canManage` пина; запись «кто и когда опубликовал» — в журнал
  (`portal.published`, …).

## Что может пойти не так

| риск | что делаем |
|---|---|
| API внутренний, может измениться без предупреждения | контрактные тесты на записанных ответах; команда `portals:probe` проверяет вход и «жив ли» эндпоинт; ошибки в Bugsnag с типом портала; кнопка выключается по флагу `PORTALS_ENABLED` |
| ss.ge включит SMS / TOTP / капчу на входе | вход перестанет работать; `PortalAuthFailed` + уведомление владельцу; запасной путь — сессия, залитая вручную |
| сервер GS Home получит `403 1010` или challenge от Cloudflare | первый же спайк — вызов с прод-сервера, до разработки интерфейса |
| MyHome `reCAPTCHA token` начнут проверять | вход упадёт; запасной путь — токен из браузера |
| дубли объявлений и бан аккаунта за «чужие» объекты | перед отправкой проверяем копию агента в группе дублей и `similars-exists` (ss.ge); решение по согласию собственника — к владельцу (вопрос 5) |
| один слот черновика ss.ge | очередь с одним воркером, `get-draft` перед созданием, `delete-draft` при сбое |
| фото чужие, большие или в странном формате | скачиваем с таймаутом, перекодируем в JPEG ≤ 2000 px, лимит числа фото (по портал-лимиту) |
| описание содержит контакты собственника | `PhoneScrubber` + предпросмотр агенту |
| правка цены и описания может уйти на модерацию | проверено только на комнатах; цену и описание проверить спайком (этап 0) |
| срок 30 дней у MyHome | `expires_at`, напоминание и продление (этап 3) |

## Открытые вопросы (нужно решение владельца)

1. **Чьи аккаунты на порталах.** Один общий аккаунт агентства (как сейчас) или у каждого агента или
   компании свой? Общий проще, но ss.ge примет только подтверждённые телефоны аккаунта. Если нужны
   телефоны всех агентов — их надо подтвердить на портале (SMS) или завести отдельные аккаунты.
   От ответа зависит таблица `portal_accounts` (логин и пароль на компанию).
2. **Подойдёт ли номер агента** на каждом портале (спайк 1).
3. **Что публикуем на первом шаге:** только аренда квартир (всё проверено) или и продажа (id для продажи
   на ss.ge не снят) и дома/участки.
4. **Какой номер агента**, если их несколько (личный / офисный).
5. **Согласие собственника.** Нужно ли в окне подтверждение «есть согласие собственника на публикацию»
   и хранить ли его. Это условие площадок и закона о рекламе, не техническая деталь.
6. **Кто может снимать** объявление с портала и что делать при закрытии пина (снять автоматически
   или спросить).

## Что не входит (сейчас)

- Платные услуги (VIP, SUPER VIP, «срочно»): только бесплатный «стандарт».
- Видео и 360° на ss.ge, `youtube_link` и `3d_url` на MyHome.
- Дома, участки, коммерция, посуточная аренда.
- Вход через Google / Facebook и официальные API, если порталы их выдадут: тогда меняется только `*Auth`.
- Автоматическая публикация без нажатия кнопки.

## Этапы

0. **Спайки (без интерфейса):** вызов с прод-сервера; номер агента на обоих порталах; ответы upload и
   create на MyHome; эндпоинт снятия на MyHome; id для продажи на ss.ge; модерация на правке цены и описания;
   лимит фото; лимит публикаций в месяц (`GET /v1/users/statements/monthly-count`).
1. **Публикация:** клиенты, `DraftBuilder`, таблица, джоба, окно и секция на карточке, уведомления.
2. **Правка:** «Обновить на порталах» после изменения цены, описания или фото в окне.
3. **Снятие и продление:** «Снять», автоснятие при закрытии пина, продление MyHome за 3 дня до `expires_at`.
4. **Аккаунты по компаниям:** `portal_accounts` с шифрованием, если решено (вопрос 1).
