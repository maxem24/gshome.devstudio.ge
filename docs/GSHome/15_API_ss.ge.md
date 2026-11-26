---
tags: [gshome, ss.ge, api, research]
---

# Внутренний API ss.ge: публикация объявлений (разведка 26.11.2026)

Цель — публиковать объявления агентства на ss.ge из GS Home. **Главная функция (владелец
проекта, 26.11):** агент выбирает объявление (например, найденное на MyHome) и
**переопубликовывает его на ss.ge от агентства**: со своим именем и телефоном, своей ценой,
своим описанием и порядком фото. Главный нерешённый вопрос — **как получать токен** от имени
аккаунта агентства (логин и пароль у владельца есть). Интеграция — отдельной задачей.

 Официального API для агентов
ss.ge не выдал (на письма и звонки 25–26.11 не ответили), поэтому снят внутренний API,
которым пользуется сам сайт `home.ss.ge`. Всё ниже проверено живыми запросами под
аккаунтом `giorgi` (частное лицо, `userType: Individual`, `agencyId: null`) в браузере
bigmed. Для аккаунта агентства часть полей может отличаться — проверить отдельно.

> **Примечание (26.11).** Цикл затем повторён под аккаунтом GS Home (брокер, `RealEstateCompanyId 9255`)
> — см. журнал ниже. Проект публикации из GS Home — `docs/superpowers/specs/2026-11-26-portal-publishing-design.md`.

Тестовые объявления: **37468164** (через форму сайта) и **37468168** (прямыми запросами
к API) — оба опубликованы бесплатно и удалены (статус 3). Пройден полный цикл:
черновик → фото → публикация → правка → удаление.

## Архитектура и авторизация

| что | значение |
|---|---|
| сайт | Next.js, `home.ss.ge`; сессия — NextAuth (`/api/auth/session`) |
| API | `https://api-gateway.ss.ge/v1/...`, JSON, заголовок `Authorization: Bearer <access_token>` |
| выдача токена | IdentityServer4 `https://account.ss.ge` (issuer), клиент **`ssweb`** |
| токен | JWT, живёт **60 минут**; scope включает `offline_access` → есть refresh-токен |
| audience | `web_apigateway`, `real_estate`, `paid_services`, `files`, `statistics`, … |
| discovery | `https://account.ss.ge/.well-known/openid-configuration` |
| token endpoint | `https://account.ss.ge/connect/token` |
| grant types (объявлены) | `authorization_code`, `refresh_token`, `password`, `client_credentials`, `SmsConfirm`, `Totp`, `TwoFactor`, `Google`, `facebook`, `device_code` |
| вход на сайте (NextAuth providers) | `identity-server4`, `identity-server4-prompt-none`, `google`, `SmsConfirm` (credentials), `Totp` (credentials) |

**Не проверено:** разрешён ли `password` grant клиенту `ssweb` и нужен ли client secret.
Если да — сервер GS Home сможет сам получать токен по логину/паролю агентства и
обновлять его refresh-токеном. Если нет — схема «один раз войти в браузере → сохранить
refresh-токен → обновлять». Возможна SMS/TOTP-проверка при входе.

## Цикл объявления

### 1. Черновик — `POST /v1/RealEstate/create-draft`

`realEstateApplicationId: 0` создаёт новый черновик, иначе — сохраняет существующий.

```json
{
  "realEstateTypeId": 5, "realEstateDealTypeId": 1, "cityId": 95,
  "currencyId": 1, "showSiteCurrencyId": 1, "priceType": 1,
  "price": 1500, "priceUsd": 578.66, "unitPrice": 27.27, "unitPriceUsd": 10.52,
  "phoneNumbers": [{"hasViber": false, "hasWhatsapp": false, "isApproved": true, "isMain": false, "phoneNumber": "557204047"}],
  "contactPerson": "giorgi", "messagingOn": true, "phoneCallsOn": true,
  "locationLatitude": 41.72806215, "locationLongitude": 44.76771879444295,
  "subdistrictId": 3, "streetId": 1086, "streetNumber": "1",
  "park": 3, "subway_station": "სამედიცინო უნივერსიტეტი", "supermarket": 15,
  "mapInfo": {"park": 3, "subway_station": "სამედიცინო უნივერსიტეტი", "supermarket": 15},
  "rooms": 2, "bedrooms": 1, "totalArea": 55, "floor": 5, "floors": 10,
  "status": 453, "state": 16,
  "descriptionGe": "…", "moderationBlockCategories": null,
  "realEstateApplicationId": 0
}
```

Ответ: `{"statusCode":200,"success":true,"userMessage":"მონაცემები წარმატებით შეინახა","applicationId":37468168}`.
Сайт пересохраняет черновик после каждого шага формы.

### 2. Фото — `POST /v1/RealEstate/upload-image`

```json
{"applicationId": 37468168, "content": "data:image/jpeg;base64,/9j/4AAQ…"}
```

Ответ: `{"isSuccess":true,"imageId":131180244,"fileName":"20261125/4_<uuid>.jpg","fileUrl":"https://static.ss.ge/20261125/4_<uuid>.jpg"}`.
Дальше фото попадает в объявление полем:

```json
"images": [{"applicationImageId": 131180244, "fileName": "20261125/4_<uuid>.jpg", "isMain": true, "is360": false, "orderNo": 0, "imageRotation": 0}]
```

Видео — `POST /v1/RealEstate/upload-video` (не проверялось).

### 3. Публикация — `POST /v1/PaidService/create-application`

```json
{
  "application": { …тело черновика + "images", "realEstateApplicationId": 37468168, "userType": "Individual" },
  "paidServices": {
    "source": "Create Application", "sourceDevice": "Mac OS/Chrome", "isCreate": true,
    "items": [{"applicationId": 37468168, "rubric": "RealEstate", "realEstateDealTypeId": 1, "cityId": 95, "paidServices": []}],
    "purchaseOptions": {"flow": 1}
  }
}
```

`paidServices: []` — бесплатный «стандарт». Ответ: `application.success = true`,
`detailsUrl` (`Аренда-2-комнатная-Квартира-Сабуртало-37468168`), `title` на KA/EN/RU
генерируется сайтом сам, `payment.success = true`, `notEnoghBalance: false`.
Платные статусы (VIP 2 ₾/день, VIP+ 4 ₾, SUPER VIP 10 ₾, «срочно» 5 ₾, «цвет» 0.30 ₾,
Top 10 ₾) — тарифы `GET /v1/PaidService/paid-service-tariff`; оплата с баланса аккаунта.

Перед публикацией сайт ещё вызывает `POST /RealEstate/user-own-similars` и
`POST /RealEstate/similars-exists` `{applicationId}` (проверка дублей).

### 4. Правка — `POST /v1/RealEstate/create-edit-application`

**Правка применяется сразу, если в теле есть `"isEdit": true`** (проверено 26.11.2026, п. 9 журнала).
Сайт делает два запроса, оба с флагом:

1. `GET /v1/RealEstate/get-edit-application?applicationId=<id>&isEdit=true` — текущие данные
   опубликованного объявления (с `isEdit=true` отдаёт их и без черновика правки);
2. `POST /v1/RealEstate/create-edit-application` — то же тело с изменениями и `"isEdit": true`.
   Объявление остаётся в выдаче (`Active`), заголовок пересчитывается, модерации нет.

**Без `isEdit`** (старый наш способ) тело только сохраняется как «черновик правки» (статус 6),
объявление снимается с выдачи, правка не применяется — см. п. 5–7 журнала.

- Тело из ответа `get-edit-application` без переделки **не подходит**: 400, поля-перечисления
  (`toilet` и др.) там в другом формате, сервер требует `model`. Тело собирают из ответа: берут
  поля как есть, `images` и т. п. приводят к формату создания (как это делает
  `create_listing.py --edit`).
- Вариант «как на сайте» = плоское тело + `userId`, `isModerator: false`, `applicationStatus: 0`,
  булевы удобства, `videos: []`, `park/pharmacy/school/supermarket`, `isEdit: true`.
  Короткое тело (только основные поля + `isEdit: true`) тоже работает — достаточно флага.
- Описание изменилось. **Цена не менялась в этих опытах** (раньше: отправили 1450, осталась 1500 —
  это было без `isEdit`, т. е. правка просто не применилась; повторить с `isEdit`).
- `GET /v1/RealEstate/get-edit-application?applicationId=…` **без** `isEdit` до правки отвечает
  `statusCode 500, "Draft application not found"` (при HTTP 200!).

### 5. Удаление — `POST /v1/RealEstate/change-application-status`

```json
{"applicationId": [37468168], "blockReason": "…", "isModeration": true, "status": 3}
```

Ответ — пустая строка, HTTP 200. Удаление мягкое: объявление уходит во вкладку
«удалённые» (`status 3`). Скрыть / показать — `hide-application` / `unhide-application`.

### Служебные

| запрос | что |
|---|---|
| `POST /v1/RealEstate/user-applications-count` `[0..7]` | счётчики по статусам (0 — активные, 3 — удалённые, …) |
| `POST /v1/RealEstate/user-applications` `{page,pageSize,status,searchString,sortBy,advancedSearch,isAlmostExpired}` | мои объявления |
| `GET /v1/RealEstate/get-draft`, `POST delete-draft`, `POST dublicate-application-draft` | черновики |
| `GET /v1/Account/profile` | профиль: `type`, `contactName`, `agencyId`, `agencyName` |
| `GET /v1/Account/user-approved-phone-number-list-with-phone-check?userId=` | подтверждённые телефоны (в объявление — только они) |
| `GET /v1/RealEstate/currency-rate` | курс для пересчёта ₾ ↔ $ |

## Справочники и значения (что встретилось)

| поле | значение |
|---|---|
| `realEstateTypeId` | 5 — квартира (ბინა) |
| `realEstateDealTypeId` | 1 — аренда помесячно |
| `cityId` | 95 — Тбилиси |
| `subdistrictId` | 3 — Сабуртало (по улице) |
| `streetId` | 1086 — пр. Важа-Пшавела |
| `currencyId` / `showSiteCurrencyId` | 1 — ₾ (вторая валюта считается сама, показывать можно любую) |
| `priceType` | 1 — полная цена (есть «за м²» и «договорная») |
| `status` | 453 — «старое строение» (статус здания) |
| `state` | 16 — «новый ремонт» (состояние) |
| `applicationStatus` | 0 активное, 3 удалённое, 6 — черновик правки (?) |

Справочники: `GET /v1/RealEstate/Municipalities`, `Cities{municipalitetyId}`,
`DistricsWithSubDistricts{cityId}`, `Location{keyWord}` (поиск улицы),
`find-location-by-street`, `maps-geo-coordinates`, `maps-info` (метро, парк, магазин рядом).
Полные списки id — снять отдельно.

## Весь список эндпоинтов из JS сайта

`RealEstate`: create-draft, create-edit-application, get-draft, delete-draft,
dublicate-application-draft, get-edit-application, edit-exclusive-application,
upload-image, upload-video, delete-video, add-watermark-position, change-application-status,
hide-application, unhide-application, user-applications, user-all-applications,
user-applications-count, get-active-applications, calculate-price, average-price,
unit-average-price, currency-rate, application-phones, phone-number, similars-exists,
img-similarity-apps, image-search, maps-*, Location, Cities, DistricsWithSubDistricts.
`PaidService`: create-application, create-and-pay-application-paid-service,
activate-application-paid-service, paid-service-tariff, get-tariffs, tariff-list, … .
`Agent`: change-invitation-status. `Company`: transfer-balance. `Statistics`: просмотры.

## Получение токена — что проверено (26.11.2026)

| способ | результат |
|---|---|
| **`password` grant, client_id `ssweb`** на `https://account.ss.ge/connect/token` | **НЕ работает: `400 invalid_client`.** Клиент `ssweb` требует client secret, которого у нас нет (он только на сервере ss.ge). Пароль агентства при этом даже не проверялся — отказ на уровне клиента. Скрипт теста — `tmp/ssge-token.py`. |

Прямой машинный вход по логину/паролю закрыт. Что может сработать (по убыванию
надёжности):

1. **Сессия сайта (рекомендую).** Агентство один раз входит на `home.ss.ge` в браузере;
   `GET https://home.ss.ge/api/auth/session` по cookie сессии каждый раз отдаёт свежий
   `access_token` (60 мин) — именно так и создавалось объявление 37468168. Хранить cookie
   сессии (NextAuth) в секрете на сервере GS Home, перед публикацией дёргать `/api/auth/session`
   за токеном. Минус: cookie живёт ограниченно (у NextAuth обычно ~30 дней), потом вход
   повторить. Проверить срок и имя cookie.
2. **Автоматический вход headless-браузером.** Сервер GS Home сам входит на ss.ge по
   `SSGE_LOGIN`/`SSGE_PASSWORD`, когда сессия истекла (Playwright/Puppeteer), и забирает
   токен из `/api/auth/session`. Без участия человека, но ломается, если ss.ge включит
   SMS-код, TOTP или капчу при входе (провайдеры `SmsConfirm`, `Totp` в NextAuth это
   допускают).
3. **Свой OAuth-клиент от ss.ge.** Попросить у ss.ge отдельный `client_id` + secret с
   `password` или `client_credentials` grant (это и есть «официальный API», которого ждём).
   Самый правильный путь, но зависит от ответа ss.ge.
4. **Клиент мобильного приложения ss.ge.** У приложения может быть свой `client_id` с
   входом по паролю (снять через прокси трафика телефона). Обходной, на крайний случай.

**Для схем 1–2 логин/пароль хранить на сервере в `.env`, не в git** (в GS Home фактически
заведены `SS_USERNAME` / `SS_PASSWORD`; в первой версии документа были `SSGE_LOGIN` /
`SSGE_PASSWORD` — имена привести к одному при реализации). Схема 2 проверена на живом
аккаунте, см. ниже; схема 1 (cookie сессии) не нужна.

### Схема 2 проверена: вход без браузера работает (26.11.2026)

Войти по логину и паролю можно обычными HTTP-запросами, без Playwright и без ручного входа.
Цепочка (записана реальным входом в браузере и повторена скриптом):

| # | запрос | что происходит |
|---|---|---|
| 1 | `GET home.ss.ge/api/auth/csrf` | csrf-токен и cookie NextAuth |
| 2 | `POST home.ss.ge/api/auth/signin/identity-server4` (`csrfToken`, `callbackUrl`, `json=false`) | 302 → `account.ss.ge/connect/authorize` → 302 → страница входа |
| 3 | `POST account.ss.ge/ka/account/Login` (`userName`, `password`, `returnUrl`) | AJAX, ответ **HTTP 200 JSON** `{"redirectUrl": "/connect/authorize/callback?…"}` |
| 4 | `GET <redirectUrl>` | 302 → `home.ss.ge/api/auth/callback/identity-server4?code=…` → 302 → `home.ss.ge/ka` |
| 5 | `GET home.ss.ge/api/auth/session` | JSON `{user, accessToken, idToken}` |

Проверка: вход выполнен по логину и паролю из `.env` GS Home (`SS_USERNAME` / `SS_PASSWORD`).
Это **другой аккаунт, чем у Макса** (тот — `giorgi`, `Individual`, `agencyId: null`): в токене
`preferred_username: jshome926@gmail.com`, `name: Js`, `UserEntityType: Broker`,
`RealEstateCompanyId: 9255`. С этим токеном `GET /v1/Account/profile` → `200`; в профиле
`type: 1`, а поля агентства (`agencyId`, `agencyName`, `agencyCode`, …) пустые, баланс `0.8`.
То есть аккаунт GS Home заведён как брокер с номером компании в токене, но профиль агентства
в ss.ge не заполнен — для публикации от агентства это нужно выяснить отдельно.
Токен — JWT клиента `ssweb`, живёт **60 минут**; в `aud` — `real_estate`, `paid_services`,
`files`, `web_apigateway` и др. Refresh-токен в сессии **не отдаётся**: токен обновляется
повторным входом.

Публикацию и правку под этим аккаунтом проверили 26.11 — см. «Журнал» ниже (объявление
37478307). Тесты Макса (37468164, 37468168) сделаны под его аккаунтом.

Что легко сломать при повторении:
- скрытое поле формы `returnUrl` на странице — шаблон `${returnUrl}`; настоящее значение ставит JS
  страницы из адреса (параметр `returnUrl`). Отправлять шаблон нельзя;
- на странице две формы (`Login` и `SendCode`) — поля брать только из `Login`;
- успех входа — это `200` с JSON, а не редирект; «осталось на account.ss.ge» не признак ошибки;
- `GET home.ss.ge/api/auth/signin` редиректит на `localhost:3001` — вход начинать POST-ом из шага 2.

Капчи на входе нет. Если ss.ge добавит SMS/TOTP/капчу — скрипт остановится с сообщением.

Скрипт: `~/.cursor/script-library/ss-ge-bearer-token/get_token.py` (стандартная библиотека
Python; логин и пароль — `SS_USERNAME` / `SS_PASSWORD` в `.env`, не в git). Для сервера GS Home
на PHP этот же порядок запросов переносится в сервис, который кэширует токен на ~55 минут.

### Тестовая публикация под аккаунтом GS Home (26.11.2026)

Первое объявление под брокерским аккаунтом GS Home: **`37478307`** — аренда помесячно,
квартира, 3 комнаты, 2 спальни, 75 м², этаж 5/9, 900 ₾, Тбилиси → Варкетили, корпус 49.
После публикации — статус **Active**, страница открывалась:
`https://home.ss.ge/ka/udzravi-qoneba/qiravdeba-3-otaxiani-bina-varketili-37478307`.
**После правки 3 → 2 комнаты объявление ушло из выдачи (статус 6); повторная публикация
вернула его в Active уже с 2 комнатами, 1 спальней и новым описанием** — см. «Журнал» ниже.
Скрипт: `~/.cursor/script-library/ss-ge-create-listing/create_listing.py` (удаление теста — `--delete 37478307`).

Что выяснилось:
- **Заголовок задать нельзя** — сайт строит его сам: `ქირავდება 3 ოთახიანი ბინა ვარკეთილში`
  (EN `3 room Flat For Rent. Varketili`). Свой текст («ქირავდება სამოთახიანი ბინა ვარკეთილში,
  49 კორპუსი») записан в `descriptionGe`, на странице он есть.
- **Под брокером публикация работает так же**, как у Макса: `create-draft` → `create-application`
  с `paidServices: []`, отличие одно — `userType: "Broker"` (допустимы `Individual`, `Operator`,
  `Broker`; сайт берёт из сессии: `UserEntityTypesPerRubric` истинно → `Broker`).
- **Фото не обязательны**: опубликовано с `images: []`, HTTP 200.
- В аккаунте уже много объявлений (счётчики: активных 51, статус 1 — 221, удалённых 4):
  это рабочий аккаунт, а не пустой тестовый.
- **Пути справочников — без слэша перед параметром** (так генерирует клиент сайта):
  `GET /v1/RealEstate/DistricsWithSubDistricts95`, `GET /v1/RealEstate/Location<слово>`.
  Варианты с `?cityId=` и `/95` дают 404.
- id: Тбилиси `cityId 95`; район `4` (ისანი-სამგორი); Варкетили `subdistrictId 9`;
  «улицы» Варкетили: I м/р `207`, II `196`, III `782`, IV `198`, `1228` — хозяйство Варкетили.
- `find-location-by-street?streetId=&streetNumber=` для Варкетили отдаёт координаты `0, 0` —
  точку на карте нужно задавать самим. В тесте это центр Варкетили из OpenStreetMap
  (41.7075, 44.8703), **не сам корпус 49**; микрорайон III выбран нами условно.
- Телефон — только из `user-approved-phone-number-list-with-phone-check` (у аккаунта один подтверждённый).
- `POST /v1/RealEstate/user-applications` с телом `{page, pageSize, status, searchString, sortBy:0,
  advancedSearch:null, isAlmostExpired:false}` вернул 500 — формат тела уточнить.

### Журнал: вход → публикация → правка, объявление 37478307 (26.11.2026)

Всё ниже сделано скриптами из `~/.cursor/script-library/` (`ss-ge-bearer-token`,
`ss-ge-create-listing`), под аккаунтом GS Home (брокер, `RealEstateCompanyId 9255`).
Секреты (токен, пароль, телефон, имя контакта) в журнал не записаны.

**1. Вход.** Цепочка из пяти запросов — в разделе «Схема 2 проверена». Итог: `accessToken`
(JWT, 60 минут). Проверка токена: `GET /v1/Account/profile` → 200.

**2. Подготовка данных (только чтение).**
- `GET /v1/Account/user-approved-phone-number-list-with-phone-check?userId=<userId из профиля>` →
  один подтверждённый номер (`isMain: true`); в объявление годятся только такие.
- `GET /v1/RealEstate/DistricsWithSubDistricts95` → район `4`, Варкетили `subdistrictId 9`.
- `GET /v1/RealEstate/Location<слово, URL-encoded>` → «улицы» Варкетили (`782` — III микрорайон).
- `GET /v1/RealEstate/currency-rate` → `geoRate 0.3858…` (₾ → $), для `priceUsd`.
- Координаты корпуса справочник не отдаёт (`find-location-by-street` → `0, 0`), точка взята из OSM.

**3. Черновик — `POST /v1/RealEstate/create-draft`** (тело как в «Цикл объявления», п. 1):

```json
{"realEstateTypeId": 5, "realEstateDealTypeId": 1, "cityId": 95,
 "currencyId": 1, "showSiteCurrencyId": 1, "priceType": 1,
 "price": 900, "priceUsd": 347.24, "unitPrice": 12.0, "unitPriceUsd": 4.63,
 "phoneNumbers": [{"hasViber": false, "hasWhatsapp": false, "isApproved": true, "isMain": true, "phoneNumber": "<подтверждённый>"}],
 "contactPerson": "<contactName из профиля>", "messagingOn": true, "phoneCallsOn": true,
 "locationLatitude": 41.7075084, "locationLongitude": 44.8702521,
 "subdistrictId": 9, "streetId": 782, "streetNumber": "49",
 "rooms": 3, "bedrooms": 2, "totalArea": 75, "floor": 5, "floors": 9,
 "status": 453, "state": 16,
 "descriptionGe": "ქირავდება სამოთახიანი ბინა ვარკეთილში, 49 კორპუსი",
 "moderationBlockCategories": null, "realEstateApplicationId": 0}
```

Ответ: `200`, `success: true`, `applicationId: 37478307`.

**4. Публикация — `POST /v1/PaidService/create-application`**: `application` = тело черновика +
`"realEstateApplicationId": 37478307, "userType": "Broker", "images": []`; `paidServices` —
как в «Цикл объявления», п. 3 (`paidServices: []`, `isCreate: true`, `purchaseOptions: {flow: 1}`).
Ответ: `200`, `application.success: true`, `payment.success: true`, `notEnoghBalance: false`;
`title` сгенерирован сайтом: KA `ქირავდება 3 ოთახიანი ბინა ვარკეთილში`, EN
`3 room Flat For Rent. Varketili`, RU `Аренда 3-комнатная Квартира. Варкетили`;
`detailsUrl: qiravdeba-3-otaxiani-bina-varketili-37478307`.

**5. Проверка публикации.** Публичная страница: HTTP 200, в `<title>` — «ქირავდება 3 ოთახიანი
ბინა ვარკეთილში, 900 ლარი - 37478307 | ss.ge», в данных страницы `status: Active`, `rooms: 3`,
`bedrooms: 2`, `streetNumber: 49`, описание — наш текст. Счётчики аккаунта
(`POST /v1/RealEstate/user-applications-count` `[0..7]`): активных (0) — **51**, статус 6 — 0.

**6. Правка 3 → 2 комнаты — `POST /v1/RealEstate/create-edit-application`.** Тело — то же плоское
тело, что у черновика, с `"rooms": 2` и `"realEstateApplicationId": 37478307` (без `userType`
и `images`). Ответ: `200`, `success: true`; в ответе заголовок уже пересчитан: KA
`ქირავდება 2 ოთახიანი ბინა ვარკეთილში`, EN `2 room Flat For Rent. Varketili`.

Что произошло дальше (проверено):

| что смотрели | до правки | после правки |
|---|---|---|
| счётчик статуса 0 (активные) | 51 | **50** |
| счётчик статуса 6 | 0 | **1** |
| публичная страница (адрес с `-37478307` в любом написании) | объявление, `Active` | HTTP 200, но **общая страница сайта** (в `<title>` «ss.ge \| სიტყვა და საქმე…»), без объявления |
| `GET get-edit-application?applicationId=37478307` | 500 «Draft application not found» (по описанию Макса) | 200, `applicationStatus: 6`, `rooms: 3.0`, `bedrooms: 2`, `price: 900`, `isModerator: false` |

Состояние не менялось за 2+ минуты наблюдения (3 проверки с интервалом 40 с).

Что нашли сразу после правки (версия «модерация», позже скорректирована в п. 7):
- `GET /v1/RealEstate/eligible?ruleName=moderation` → `true` — аккаунт подлежит модерации;
- в JS страницы правки есть `isEligibleForModeration` и `moderationBlockCategories`;
- ранее у Макса правка цены тоже «не применилась» — это согласуется с любой из версий.

**Первая гипотеза (модерация) — не подтверждена, см. п. 7.** Сначала казалось, что правка
отправляет объявление на модерацию; п. 7 показывает другое объяснение.

Побочное (до п. 7 и 8): правка не трогала описание и спальни, поэтому у объявления было описание
«სამოთახიანი» (трёхкомнатная) при 2 комнатах и 2 спальнях.

**7. Повторная публикация вернула объявление в Active (случайно, ~15 минут после правки).**
Хотели создать второе объявление: `create-draft` с `realEstateApplicationId: 0` и затем
`create-application` (2 комнаты, 1 спальня, описание «ქირავდება 2 ოთახიანი ბინა ვარკეთილში,
49 კორპუსი»). Что вышло:

- `create-draft` с `0` вернул **не новый id, а существующий `37478307`**. У аккаунта один
  «слот» черновика: пока висит черновик правки (статус 6), `create-draft` с `0` его
  **переиспользует** и перезаписывает, а не создаёт новый;
- `create-application` по этому id опубликовал его: объявление **37478307 снова `Active`**,
  в нём уже новые значения — `rooms: 2`, `bedrooms: 1`, новое описание, заголовок
  «ქირავდება 2 ოთახიანი ბინა ვარკეთილში, 900 ლარი»;
- счётчики вернулись: активных **51**, статус 6 — **0**; `get-draft` → 500 «Draft application
  not found» (слот свободен);
- **модерации на этом шаге не было** — объявление сразу вернулось в выдачу.

**Что это значит (вывод, уже без гипотезы про модерацию):**
- статус 6 — это **черновик правки**: `create-edit-application` сохраняет правку как черновик
  и **снимает объявление с выдачи**, но не применяет её;
- *(исправлено в п. 9)* это был не второй шаг, а отсутствующий флаг `isEdit: true` в теле
  `create-edit-application`; публикация по тому же id возвращает объявление в выдачу, но для
  самой правки не нужна;
- `eligible?ruleName=moderation = true` и `moderationBlockCategories` остаются неразобранными:
  возможно, часть правок всё же уходит на модерацию, но **наши две правки комнат через API
  в модерацию не попали**;
- **для GS Home:** правка — с `isEdit: true` (п. 9). Перед созданием проверять `get-draft`:
  чужой/забытый черновик будет перезаписан при `create-draft`.

**8. Ручная правка в кабинете (пользователь, 26.11 около 18:41) — в модерацию не попала.**
Пользователь вручную изменил в кабинете ss.ge те же данные (комнаты), что мы правили через API.
Что видно после этого (проверено запросами, ход самой правки не наблюдали):

| что смотрели | значение |
|---|---|
| публичная страница `37478307` | `Active`, `rooms: 3`, `bedrooms: 1`, заголовок «ქირავდება 3 ოთახიანი ბინა ვარკეთილში, 900 ლარი», описание не менялось («2 ოთახიანი») |
| счётчики статусов (0 / 1 / 2 / 3 / 6) | 51 / 221 / 2 / 4 / **0** — как до правки, ничего не ушло в другой статус |
| `get-draft` и `get-edit-application` | 500 «Draft application not found» — висящего черновика правки нет |

То есть ручная правка **применилась сразу, объявление всё время было в выдаче или вернулось без
следов в статусе 6**, модерацией её не задержало. Это подтверждает п. 7: наша правка через API
оказалась «зависшей» не из-за модерации, а потому, что **мы сделали только первый шаг**
(`create-edit-application` → черновик, статус 6), а применение правки — отдельный шаг, который
сайт делает сам. **Уточнено в п. 9:** отдельного шага нет — дело в флаге `isEdit: true`.

**9. Запросы сайта сняты, правка через API применяется сразу (26.11.2026).**
Пользователь снял из браузера два запроса при ручной правке: `GET get-edit-application?applicationId=37478307&isEdit=true`
и `POST create-edit-application` (в теле `"isEdit": true`). После «Публикации» сайт просто возвращает в
кабинет — **третьего запроса нет**. Отличие от наших старых правок — только флаг `isEdit` (+ `userId`,
`applicationStatus: 0` и пр., которые тоже были в теле сайта).

Опыты на 37478307 (скрипт `ss-ge-create-listing`, `--edit ID --rooms N --variant …`; после каждого —
публичная страница и счётчики статусов 0/1/2/3/6 и слот черновика):

| № | вариант | комнаты | HTTP / success | публично | счётчики 0/1/2/3/6 | слот черновика |
|---|---|---|---|---|---|---|
| 1 | `site` — тело как у сайта | 3 → 3 | 200 / true | Active, «3 ოთახიანი» | 51/221/2/4/**0** | свободен |
| 2 | `site` | 3 → **4** | 200 / true | Active, «4 ოთახიანი», сразу и через 20 с | без изменений | свободен |
| 3 | `core` — короткое тело + `isEdit: true` | 4 → **3** | 200 / true | Active, «3 ოთახიანი» | без изменений | свободен |
| 4 | `core` | 3 → **4** | 200 / true | Active, «4 ოთახიანი» | без изменений | свободен |

Итог: оба варианта применяют правку немедленно, объявление не снимается с выдачи, статус 6 не
появляется, заголовок пересчитывается (KA/EN/RU). Вариант без `isEdit` в этой серии намеренно **не
повторяли**: он снимает объявление с выдачи, поведение известно из п. 5–6. Объявление сейчас: 4 комнаты.

**Для GS Home:** правка = `GET get-edit-application?…&isEdit=true` → изменить поля → `POST
create-edit-application` с `isEdit: true`. Второй шаг не нужен.

Не выяснено: попадает ли в модерацию правка других полей (цена, площадь, описание, фото);
формат тела `POST /v1/RealEstate/user-applications` (у нас 500); роль `moderationBlockCategories`.

**Безопасность:** в снятых запросах были живой Bearer-токен и долгоживущий cookie
`.AspNetCore.Identity.Application`. В документацию они не вносились; их стоит считать
скомпрометированными (выйти из аккаунта на ss.ge / сменить пароль).

### Старое (до разбора токена)

1. Проверить вход по логину/паролю агентства на `connect/token` (`password` grant,
   client_id `ssweb`) — с разрешения владельца аккаунта; иначе схема с refresh-токеном.
   → **Проверено: не работает (`invalid_client`), см. выше.**
2. Повторить цикл под **аккаунтом агентства** (поля `userType`, `agencyId`, агенты компании).
   → Частично: цикл «черновик → публикация → правка» пройден под брокером GS Home (26.11);
   профиль агентства (`agencyId`) в ss.ge не заполнен, остальное не проверено.
3. Выяснить, как меняется цена (правка цены не применилась). → `create-edit-application`
   сохраняет правку как черновик (статус 6) и снимает объявление с выдачи; правка применяется
   публикацией по тому же id (п. 7 журнала). Правка именно цены отдельно не проверялась.
4. Снять справочники id (типы, сделки, города, районы, статусы, состояния) в таблицу
   соответствия с данными Real Estate.
5. Учесть: это недокументированный API, может измениться без предупреждения; условия
   использования ss.ge — проверить; частота запросов — щадящая.
