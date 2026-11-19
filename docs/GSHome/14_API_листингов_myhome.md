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
