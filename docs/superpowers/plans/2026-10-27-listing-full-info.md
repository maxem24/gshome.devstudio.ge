# Кнопка «Собрать полную информацию» на карточке объявления

Дата: 2026-10-27. Ветка `feat/listing-full-info`. API — `~/Sites/real-estate.devstudio.ge/docs/RealEstate/16_API_актуализация.md`
(прочитать целиком перед работой). Локальный Real Estate уже отдаёт `refreshable` у объявлений и `refresh_sources` в `/me`.

## Решения

- Кнопка на карточке (`app/Filament/Pages/ListingView.php`), видна при `refreshable = true` у объявления и пока
  нет незаконченной актуализации. Право — то же, что открыть карточку (mount уже проверил направление).
- Опрос — из браузера через Livewire `wire:poll` (агент сидит на карточке), не фоновая задача: таблицы и
  уведомления в колокольчик не делаем. Ушёл со страницы — результат не нужен, Real Estate всё равно сохранит
  свежие данные, следующий показ карточки их покажет.
- Id актуализации — `#[Locked]` свойство страницы: подменить его из браузера нельзя; Real Estate и так отдаёт
  только актуализации нашего ключа.
- После `done`/`stale` — забыть кеш карточки (`real-estate:card:{locale}:{id}` для ru/en/ka) и пересобрать
  `$card` из `data.listing` (без нового GET). `listing.status = inactive` → крупная плашка «Объявление снято с
  публикации <deactivated_at>», карточку оставить прежней.
- В журнал — `listing.refresh_started` (`Journal::LISTING_REFRESH_STARTED`, в ленту не идёт), payload `listing_id`.

## Задачи

### 1. DTO и клиент

- `app/RealEstate/ListingRefresh.php` — `final readonly`, `fromApi(mixed $data): self` в стиле
  `SiteSearchStatus::fromApi` (бросает `UnexpectedValueException`/`TypeError` на кривом формате — клиент
  оборачивает в `parsed()`). Поля: `id` (string), `status` (string), `etaSeconds` (int), `pollAfterMs` (int,
  не меньше 1000), `paused`, `truncated` (bool), `deadlineAt` (?Carbon), `items` (list<ListingRefreshItem>),
  `listing` (?array — сырая карточка). Методы: `finished(): bool` (`done|stale`), `stale(): bool`.
- `app/RealEstate/ListingRefreshItem.php` — `id`, `source`, `sourceUrl`, `status`, `outcome`, `checkedAt`,
  `deactivatedAt`, `error`, `pausedUntil`. Неизвестный `outcome` → `failed` (не ронять страницу).
- `Listing`: поле `public bool $refreshable = false`, из `$d['refreshable']`.
- `RealEstateClient`:
  - `startRefresh(int $id): ?ListingRefresh` — `POST /listings/{id}/refresh?lang=…` **без повторов** (как
    `startSearch`). 404 → `null`. 503 с `error.code = refresh_busy` → новое `RealEstateRefreshBusy`
    (retryAfter из заголовка, сообщение «Сервис занят, попробуйте через полминуты»). 429 → как везде.
  - `refreshState(string $id): ListingRefresh` — `GET /listing-refreshes/{id}`; 404 →
    `RealEstateSearchNotFound` не подходит по смыслу — завести `RealEstateRefreshNotFound`.
  - `forgetCard(int $id): void` — убрать кеш карточки на всех языках `config('app.supported_locales')` или
    явный список ru/en/ka (посмотреть, как сделано в проекте).
  - Публичный статический (или публичный) способ собрать `Listing` из сырой карточки уже есть: `Listing::fromApi`.

### 2. Карточка

- Вынести сборку `$this->card` из `mount()` в `private function fill(Listing $l, Direction $direction): void`,
  `mount()` её зовёт. Добавить в `card`: `refreshable`, `partial`.
- Свойства: `#[Locked] public ?string $refreshId = null`, `#[Locked] public array $refresh = []` — готовое к
  показу состояние: `status`, `eta_minutes` (ceil(eta/60), минимум 1), `deadline` (ISO), `items` (сайт,
  ссылка, текст итога, `error`), `removed` (bool + дата), `price_before` (строка цены до), `summary`.
- Действие `collectFullInfo`: подпись `gshome.listings.refresh.button` («Собрать полную информацию»),
  иконка `Heroicon::OutlinedArrowPath`, цвет primary, видимо при `card.refreshable && refreshId === null`.
  Ловит `RealEstateException` → уведомление `userMessage()`. Сразу `finished()` (всё `recent`) — сразу
  применить результат.
- `public function pollRefresh(): void` — если `refreshId` пуст, ничего. Читает состояние, обновляет `$refresh`;
  при `finished()` применяет результат и обнуляет `refreshId`. После `deadline + 15 с` без конца — остановить
  с текстом «Сайты не ответили вовремя». Ошибка API при опросе — не сбрасывать, просто показать текст.
- Применение результата: `forgetCard`, если `listing` есть и `status = active` и направление разрешено — `fill()`
  заново (направление проверить так же, как в mount; если стало запрещено — не обновлять, просто плашка).
  Если цена изменилась — «Цена изменилась: было X». `inactive` — плашка «снято». Итог по сайтам одной строкой:
  например «ss.ge — обновлено; myhome.ge — myhome временно ограничил запросы — проверим после 20:09.»
  (`error` из API выводить как есть). `stale` — «Не все сайты ответили, данные на <checked_at>».
- Вид `resources/views/filament/pages/listing-view.blade.php`: плашка над карточкой. Пока идёт —
  `wire:poll.{pollAfterMs}ms="pollRefresh"` и текст «Собираем данные с сайтов, обычно до N мин. Можно не ждать
  на странице» со списком копий и их итогами (`pending` — «проверяем…»). По концу — итог, без `wire:poll`.
  Стиль — как остальные плашки на этой странице (посмотреть, как сделан баннер закрепления).

### 3. Переводы, журнал

- `lang/{ru,en,ka}/gshome.php` → `listings.refresh.*`: button, started, running, eta, done, stale, removed,
  price_changed, outcomes (pending/refreshed/recent/removed/failed), timeout, busy. Грузинский — нормальный
  грузинский, не транслит.
- `Journal::LISTING_REFRESH_STARTED = 'listing.refresh_started'`, подпись события в журнале там же, где у
  `LISTING_VIEWED`.

### 4. Тесты

- Unit: `ListingRefresh::fromApi` на примере из справки (202) и на неизвестном outcome.
- Feature (`Http::fake`, как в существующих тестах `tests/Feature/RealEstate` и `tests/Feature/Filament`
  для ListingView): кнопка видна при `refreshable`, скрыта без него; нажатие ставит `refreshId`; опрос `done`
  обновляет цену в `card` и сбрасывает `refreshId`; `inactive` → `refresh.removed`; 503 `refresh_busy` →
  уведомление, `refreshId` пуст; POST не повторяется (ровно один запрос при 500).

### 5. Проверка

`docker compose exec app composer analyse`, точечные тесты `--filter`, `docker compose exec app vendor/bin/pint` на
изменённых файлах. Не коммитить — это сделает координатор.
