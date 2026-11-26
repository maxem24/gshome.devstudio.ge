---
tags: [gshome, api, myhome]
---

# API листингов MyHome

Рабочая тетрадь: запросы к API MyHome (TNET statements) для публикации объявлений.
Наполняем по мере поступления примеров из Postman / cURL.

**Статус 2026-11-19:** оба запроса ниже проверены вручную — HTTP 200, объявление
появилось на учётной записи.

## База и авторизация

| Что | Значение |
|---|---|
| Create / statements | `https://api-statements.tnet.ge` |
| Upload images | `https://static-api-statements.tnet.ge` |
| Auth header | `Global-Authorization: <token>` |
| Accept | `application/json` |

Токен в git не кладём. В примерах — плейсхолдер `YOUR_NEW_AUTH_TOKEN`.

### Как получить токен без браузера (проверено 26.11.2026)

Одним запросом, тем же, что отправляет форма `auth.tnet.ge`:

```bash
curl -X POST 'https://accounts.tnet.ge/api/ka/user/auth' \
  -H 'Content-Type: application/json' \
  -H 'Origin: https://auth.tnet.ge' -H 'Referer: https://auth.tnet.ge/' \
  -d '{"Email":"MYHOME_USERNAME","Password":"MYHOME_PASSWORD","token":"","Continue":"https://www.myhome.ge/"}'
```

- Поле `token` оставляем **пустым** — сервер принимает. Это поле для ответа invisible reCAPTCHA
  (в браузере форма входа её запускает: `google.com/recaptcha/api2/…`, `reload`, `clr`). Пустое
  значение сейчас принимается, но это может измениться. Хеширования нет: пароль уходит открытым текстом по HTTPS.
- Скрипт и запросы к API обязаны слать браузерный `User-Agent`: иначе Cloudflare отвечает
  `403 Error 1010` (проверено 26.11).
- Ответ: `data.access_token` (JWT, живёт ~11 минут — 660 с) и `data.refresh_token` (~120 дней).
  Куки не ставятся. Эндпоинт обновления по refresh-токену неизвестен — проще войти заново.
- **`access_token` = значение `Global-Authorization`.** Проверка: `POST /v1/statements/create`
  с неполным телом отвечает `401 Unauthenticated` на плохой токен и `422` (ошибки валидации
  полей) на наш.
- Скрипт: `~/.cursor/script-library/myhome-bearer-token/get_token.py --env-file .env`
  (учётные данные — `MYHOME_USERNAME` / `MYHOME_PASSWORD` в `.env`).
- Аккаунт в `.env` — `user_id` 9224739, тот же ящик, что и на ss.ge.
- Не проверено: публикация, правка и удаление с этим токеном; эндпоинты правки/удаления в
  документе не описаны.

## Эндпоинты

| # | Метод | URL | Назначение |
|---|---|---|---|
| 1 | `POST` | `/v1/files/upload-image` | Загрузить фото, получить `image_id` + `url` |
| 2 | `POST` | `/v1/statements/create` | Создать / опубликовать объявление (multipart form) |

Порядок на практике: сначала upload картинок, затем create с `images[n][image_id]` и
`images[n][url]`.

---

## 1. Upload image

Хост: `https://static-api-statements.tnet.ge`

```bash
curl --location 'https://static-api-statements.tnet.ge/v1/files/upload-image' \
  --header 'Accept: application/json' \
  --header 'Global-Authorization: YOUR_NEW_AUTH_TOKEN' \
  --header 'locale: ka' \
  --header 'Origin: https://statements.myhome.ge' \
  --header 'Referer: https://statements.myhome.ge/' \
  --header 'x-referrer-key: myhome' \
  --header 'x-website-key: myhome' \
  --form 'file=@"/PATH/TO/YOUR/IMAGE.jpg"'
```

### Заголовки

| Header | Значение в рабочем запросе |
|---|---|
| `Accept` | `application/json` |
| `Global-Authorization` | токен |
| `locale` | `ka` |
| `Origin` | `https://statements.myhome.ge` |
| `Referer` | `https://statements.myhome.ge/` |
| `x-referrer-key` | `myhome` |
| `x-website-key` | `myhome` |

### Form fields

| Field | Описание |
|---|---|
| `file` | Файл изображения |

Ответ (тело) — пока не зафиксирован; из create видно, что дальше нужны `image_id` и `url`
на static-хосте.

---

## 2. Create statement

Хост: `https://api-statements.tnet.ge`  
Content-Type: `multipart/form-data`

```bash
curl --location 'https://api-statements.tnet.ge/v1/statements/create' \
  --header 'Accept: application/json' \
  --header 'Global-Authorization: YOUR_NEW_AUTH_TOKEN' \
  --form 'real_estate_type_id=1' \
  --form 'deal_type_id=2' \
  --form 'rent_period=6' \
  --form 'city_id=1' \
  --form 'street_id=1389' \
  --form 'location_id=201' \
  --form 'district_id=4' \
  --form 'urban_id=47' \
  --form 'rs_code=' \
  --form 'appear_rs_code=0' \
  --form 'longitude=44.763413' \
  --form 'latitude=41.7277403' \
  --form 'duration_id=1' \
  --form 'status_id=2' \
  --form 'project_type_id=8' \
  --form 'room_type_id=3' \
  --form 'floor=8' \
  --form 'total_floors=16' \
  --form 'condition_id=5' \
  --form 'living_room_area=' \
  --form 'loggia_area=' \
  --form 'porch_area=' \
  --form 'waiting_space_area=' \
  --form 'storeroom_area=' \
  --form 'living_room_type_id=' \
  --form 'storeroom_type_id=' \
  --form 'area=80' \
  --form 'area_type_id=1' \
  --form 'price_type_id=3' \
  --form 'total_price=40000' \
  --form 'square_price=500' \
  --form 'currency_id=1' \
  --form 'phone_number=557204047' \
  --form 'is_phone_number_visible=1' \
  --form 'youtube_link=' \
  --form '3d_url=' \
  --form 'ka[comment]=PASTE_ORIGINAL_GEORGIAN_COMMENT' \
  --form 'en[comment]=' \
  --form 'ru[comment]=' \
  --form 'ka[address]=PASTE_ORIGINAL_ADDRESS' \
  --form 'en[address]=PASTE_ORIGINAL_ADDRESS' \
  --form 'ru[address]=PASTE_ORIGINAL_ADDRESS' \
  --form 'ka[owner_name]=Giorgi Oshayamashvili' \
  --form 'en[owner_name]=Giorgi Oshayamashvili' \
  --form 'ru[owner_name]=Giorgi Oshayamashvili' \
  --form 'ka[street_number]=6' \
  --form 'en[street_number]=6' \
  --form 'ru[street_number]=6' \
  --form 'images[0][image_id]=113882093' \
  --form 'images[0][url]=https://static-api-statements.tnet.ge/uploads/202611/20261119/statements/nOuDQOn6aff1b53c97cd.webp' \
  --form 'images[1][image_id]=113882094' \
  --form 'images[1][url]=https://static-api-statements.tnet.ge/uploads/202611/20261119/statements/WWfcgrF6aff1b53cc471.webp' \
  --form 'images[2][image_id]=113882096' \
  --form 'images[2][url]=https://static-api-statements.tnet.ge/uploads/202611/20261119/statements/4DYRORR6aff1b53d0e5a.webp' \
  --form 'websites[0]=2'
```

### Form fields (снимок рабочего запроса)

Значения — из успешного Postman-запроса 2026-11-19; смысл id-справочников пока не
расшифрован.

| Field | Пример | Примечание |
|---|---|---|
| `real_estate_type_id` | `1` | |
| `deal_type_id` | `2` | |
| `rent_period` | `6` | |
| `city_id` | `1` | |
| `street_id` | `1389` | |
| `location_id` | `201` | |
| `district_id` | `4` | |
| `urban_id` | `47` | |
| `rs_code` | _(пусто)_ | |
| `appear_rs_code` | `0` | |
| `longitude` | `44.763413` | |
| `latitude` | `41.7277403` | |
| `duration_id` | `1` | |
| `status_id` | `2` | |
| `project_type_id` | `8` | |
| `room_type_id` | `3` | |
| `floor` | `8` | |
| `total_floors` | `16` | |
| `condition_id` | `5` | |
| `living_room_area` | _(пусто)_ | |
| `loggia_area` | _(пусто)_ | |
| `porch_area` | _(пусто)_ | |
| `waiting_space_area` | _(пусто)_ | |
| `storeroom_area` | _(пусто)_ | |
| `living_room_type_id` | _(пусто)_ | |
| `storeroom_type_id` | _(пусто)_ | |
| `area` | `80` | |
| `area_type_id` | `1` | |
| `price_type_id` | `3` | |
| `total_price` | `40000` | |
| `square_price` | `500` | |
| `currency_id` | `1` | |
| `phone_number` | `557204047` | без `+995` |
| `is_phone_number_visible` | `1` | |
| `youtube_link` | _(пусто)_ | |
| `3d_url` | _(пусто)_ | |
| `ka[comment]` / `en[comment]` / `ru[comment]` | тексты | локализованный комментарий |
| `ka[address]` / `en[address]` / `ru[address]` | адрес | |
| `ka[owner_name]` / `en[…]` / `ru[…]` | имя | |
| `ka[street_number]` / `en[…]` / `ru[…]` | `6` | |
| `images[n][image_id]` | id с upload | |
| `images[n][url]` | url на static | |
| `websites[0]` | `2` | | **нужна расшифровка** — см. ниже |

---

## Справочники id

Источник известных enum’ов MyHome: Real Estate
`docs/superpowers/specs/2026-11-13-site-filters-inventory.md` §1.4 и публичный
`GET https://api-statements.tnet.ge/v1/statements/statement-parameters`.
Маппинг в нашу базу — `SourceMappingSeeder` (проект Real Estate).

### Уже расшифрованы (достаточно для create из примера)

| Поле create | Пример | Значение |
|---|---|---|
| `real_estate_type_id` | `1` | Квартира (1–6: квартира, дом, дача, участок, коммерция, гостиница) |
| `deal_type_id` | `2` | Сдаётся (1 продажа, 2 аренда, 3 залог, 7 посуточно, …) |
| `project_type_id` | `8` | Нестандартный (проект / планировка здания) |
| `status_id` | `2` | Новое здание (1 старое, 2 новое, 3 строится) |
| `condition_id` | `5` | Белый каркас (1–8) |
| `room_type_id` | `3` | 3 комнаты |
| `area_type_id` | `1` | м² |
| `currency_id` | `1` | GEL (`2` = USD) |
| `duration_id` | `1` | 30 дней (`2`=90, `3`=180) |
| `city_id` | `1` | Тбилиси |
| `district_id` | `4` | Ваке-Сабуртало |
| `urban_id` | `47` | Сабуртало |

### TODO — найти и вписать (в документации у нас нет)

Поля из рабочего create, для которых нет полного справочника / смысла кодов.
Когда найдём — дописать сюда id → значение (и откуда взяли).

| Поле | Пример в запросе | Что нужно выяснить | Найдено |
|---|---|---|---|
| `rent_period` | `6` | Полный enum периодов аренды (месяцы? что значит 6) | |
| `price_type_id` | `3` | Типы цены (за объект / за м² / …); полный список id | |
| `location_id` | `201` | Что это за сущность рядом с `city`/`district`/`urban`/`street`; откуда брать id | |
| `websites[]` | `2` | Каналы публикации (myhome / другие сайты TNET?); полный список | |
| `street_id` | `1389` | Не один код, а **справочник улиц** (или API поиска улиц по городу/району) | |
| `living_room_type_id` | _(пусто)_ | Известно частично из поиска: `3` отдельная, `4` студия — подтвердить для create | |
| `storeroom_type_id` | _(пусто)_ | Из поиска: 5–10 (подвал, чердак, …) — подтвердить для create | |

Не путать: `project_type_id` (проект/планировка) и `status_id` (старое/новое/строится) —
оба уже расшифрованы; отдельного поля `BuildTypeID` в create нет.

---

## Ответы

Пока не сохранены. Известно: HTTP 200, объявление опубликовано на учётке.

## Ошибки

_(пока пусто)_

## Открытые вопросы

- Тело ответа upload и create.
- Нужны ли `Origin` / `Referer` / `x-*-key` только для upload или и для create.
- Как обновлять / снимать объявление (если есть отдельные эндпоинты).
- Маппинг MyHome id → Real Estate / GS Home при публикации из CRM (кроме уже
  заведённых в `SourceMappingSeeder`).

---

## Вход в браузере и запросы кабинета (снято 26.11.2026, аккаунт `jshome926@gmail.com`)

Снято через Chrome DevTools MCP в изолированной вкладке, вход руками пользователя. Токены и cookie
в документ не переносим: в снятых запросах они живые.

### Цепочка входа

| # | Запрос | Что происходит |
|---|---|---|
| 1 | `GET auth.tnet.ge/ka/user/login/?Continue=…statements.myhome.ge/ka/user-profile/my-statements?referrer=myhome` | форма входа (поля Email / Password, invisible reCAPTCHA) |
| 2 | `POST accounts.tnet.ge/api/ka/user/auth` | **200**, тело `{Email, Password, token, Continue}`, ответ — `access_token` + `refresh_token` |
| 3 | `GET auth.myauto.ge/?AccessToken=…&RefreshToken=…&Continue=…` → `302` | SSO-«развод» по сайтам TNET: `auth.myauto.ge`, `auth.mymarket.ge`, `auth.myparts.ge`, `auth.myhome.ge`, `auth.swoop.ge`, `auth.livo.ge`, `auth.myjobs.ge`, `auth.tkt.ge`, `auth.superesim.ge`. Каждый ставит cookie `AccessToken` на свой домен |
| 4 | `GET statements.myhome.ge/ka/user-profile/my-statements?referrer=myhome` | **200**, страница кабинета |

Ответ шага 2 совпадает с нашим скриптом `myhome-bearer-token`: тот же `access_token` уходит браузером
в заголовке `Global-Authorization`.

### Запросы кабинета (`api-statements.tnet.ge`)

Заголовки, которые шлёт браузер: `Global-Authorization: <access_token>`, `x-website-key: myhome`,
`x-referrer-key: myhome`, `locale: ka`, `Origin/Referer: https://statements.myhome.ge`.

| запрос | назначение |
|---|---|
| `GET /v1/users/me` | профиль: `id 9224739`, `type.key: agent`, `is_premier_agent: false`, `roles: []` |
| `GET /v1/auth/grouped-info` | данные аккаунта (email, телефон, подтверждения) |
| `GET /v1/users/get-type?websites=1,2&` | тип пользователя по сайтам |
| `GET /v1/users/statements/statuses` | счётчики по статусам (`id 2` «მიმდინარე» — текущие: 48) |
| `GET /v1/users/active-statements/count` | активных объявлений: **48** |
| `GET /v1/users/statements?website_status_id=2&q=&order_by=update_date_desc&page=1&per_page=10` | список объявлений кабинета |
| `GET /v1/locations/cities`, `GET /v2/services?websites=1,2`, `GET /v1/users/statements/monthly-count` | справочники и сервисы |

Проверено скриптом (вне браузера, тот же токен): `/v1/users/me`, `/v1/auth/grouped-info`,
`/v1/users/statements/statuses`, `/v1/users/active-statements/count` → 200.

### Что ещё не снято

Создание, правка и удаление объявления в кабинете. Список запросов будет дописан, когда пользователь
выполнит каждое действие в той же вкладке.

### Кнопка «Редактировать» (снято 26.11.2026, объявление `26671397`)

Переход: `statements.myhome.ge/ka/statement/edit/26671397?referrer=myhome`. Запросы, которые ушли при
открытии формы и после неё (по номеру в DevTools):

| reqid | запрос | что это |
|---|---|---|
| 645 | `GET /v1/statements/edit/26671397` → 200 | загрузка данных объявления (ответ: `data.statement`) |
| 644, 660, 672, 856 | `GET /v1/statements/title-preview?deal_type_id=…&real_estate_type_id=…&city_id=…&status_id=…&urban_id=…&room_type_id=…` | живой предпросмотр заголовка при заполнении формы |
| 649, 847 | `GET /v1/statements/title-preview?real_estate_type_id=1` → **422** | неполный запрос предпросмотра, без последствий |
| 653, 849, 667 | `GET api-locations.tnet.ge/v2/suggestions?with_visible_in_cities=1[&location_id=201]` | подсказки районов и локаций |
| 668, 864 | `GET /v1/users/validation/check?number=544407436` | проверка телефона |
| 640, 836 | `GET /v1/statements/statement-parameters?exclude_cities=1` | справочник удобств |
| 711 | **`PUT /v1/statements/update/26671397` → 200** | **сохранение, нажал пользователь без изменений** (см. ниже) |
| 734–793 | переход в список «Мои объявления» и его обновление | после «Сохранить» пользователь вернулся на главную кабинета |
| 845 | повторный `GET /v1/statements/edit/26671397` | пользователь снова открыл форму редактирования |

**Формат сохранения — `PUT` JSON, не форма `create`.** Тело `update` — полный объект объявления:
`real_estate_type_id`, `deal_type_id`, `rent_period`, `rent_type_id`, `city_id`, `street_id`,
`location_id`, `district_id`, `urban_id`, `longitude`, `latitude`, `duration_id`, `parameters` (массив
id удобств), `status_id`, `project_type_id`, `room_type_id`, `bedroom_type_id`, `floor`, `total_floors`,
`condition_id`, `area`, `area_type_id`, `price_type_id`, `total_price`, `square_price`, `currency_id`,
`phone_number`, `is_phone_number_visible`, `youtube_link`, `3d_url`, переводы `ka`/`en`/`ru`
(`comment`, `address`, `owner_name`, `street_number`), `images` (массив `{image_id, url}`) и
`hidden_owner_*`. Числа приходят то строками (`"floor": "1"`, `"street_id": "3697"`), то числами
(`"area": 60`) — сервер это принимает.

**Что изменилось после сохранения (проверено чтением, 26.11):**
- значения в теле совпадали с текущими — правки не было, поэтому объявление не изменилось:
  `status_id 1`, `published_by_website {"2": true}`, `is_active_by_website {"2": true}`,
  `last_updated_by_website` всё ещё `2026-11-13 21:57:25`, цена `1300`, 16 фото;
- счётчик активных объявлений — 48, как и до сохранения;
- статус 2 (`მიმდინარე`) сохранён; «на модерацию» по этим полям не видно.

Вывод: нажатие «Сохранить» без изменений — no-op на стороне данных. Реальная правка — тот же `PUT`
с изменённым полем (например, `room_type_id`). Эту часть ещё **не проверяли**.

### Правка комнат через форму (снято 26.11.2026, объявление `26671397`, 2 → 3 комнаты)

Пользователь поменял количество комнат в форме и нажал «Сохранить». Запросы из DevTools:

| reqid | запрос | что это |
|---|---|---|
| 902 | `GET /v1/statements/title-preview?…&room_type_id=3` → 200 | заголовок пересчитан на 3 комнаты |
| 915 | **`PUT /v1/statements/update/26671397` → 200** | сохранение |

**Сравнение тела 711 (без изменений) и 915 (с правкой):** отличаются два поля.
- `room_type_id`: `"2"` → `"3"`.
- `bedroom_type_id`: `1` → `null`. **Форма сама сбросила число спален при смене комнат.** Если поле
  не сохранить в теле `PUT` сознательно, спальни пропадут. Это важно для API-правки: отправлять
  `bedroom_type_id` с нужным значением.

Остальное совпало: `parameters`, `images` (16), цена `1300`, телефон, переводы, координаты.

**Состояние после правки (чтение, 26.11):**
- `room_type_id` = 3, `bedroom_type_id` = null, `status_id` = 1;
- публичный статус: `published_by_website {"2": true}`, `is_active_by_website {"2": true}`, `is_editable` = true;
- заголовок: `ქირავდება 3 ოთახიანი ძველი აშენებული ბინა კონაკის დას.-ში`;
- **`last_updated_by_website` осталась `2026-11-13 21:57:25`**: дата обновления по этому полю не меняется
  даже при реальной правке;
- активных объявлений — 48, модерации по этим полям не видно.

Вывод: правка через `PUT update` применилась сразу и без модерации (по данным API). Откатить на 2 комнаты
тем же способом (`room_type_id` = `"2"`, `bedroom_type_id` = `1`).
