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

Тело — **в том же формате, что и при создании** (плоский объект как у черновика +
`realEstateApplicationId`). Ответ `success: true`.

- Тело из ответа `get-edit-application` целиком **не подходит**: 400, поля-перечисления
  (`toilet` и др.) там в другом формате, сервер требует `model`.
- Описание изменилось. **Цена не изменилась** (отправили 1450, осталась 1500) — открытый
  вопрос: цену меняют иначе или после модерации.
- `GET /v1/RealEstate/get-edit-application?applicationId=…` до правки отвечает
  `statusCode 500, "Draft application not found"` (при HTTP 200!), после правки —
  данные с `applicationStatus: 6`. Похоже, правка создаёт «черновик правки».
  Проверить: применилась ли правка к опубликованному объявлению сразу.

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

**Для схем 1–2 логин/пароль хранить на сервере в `.env` (`SSGE_LOGIN`, `SSGE_PASSWORD`),
не в git; агент пароль сам не вводит.** Следующий шаг — проверить схему 1 (сессия сайта)
на живом аккаунте.

### Старое (до разбора токена)

1. Проверить вход по логину/паролю агентства на `connect/token` (`password` grant,
   client_id `ssweb`) — с разрешения владельца аккаунта; иначе схема с refresh-токеном.
   → **Проверено: не работает (`invalid_client`), см. выше.**
2. Повторить цикл под **аккаунтом агентства** (поля `userType`, `agencyId`, агенты компании).
3. Выяснить, как меняется цена (правка цены не применилась).
4. Снять справочники id (типы, сделки, города, районы, статусы, состояния) в таблицу
   соответствия с данными Real Estate.
5. Учесть: это недокументированный API, может измениться без предупреждения; условия
   использования ss.ge — проверить; частота запросов — щадящая.
