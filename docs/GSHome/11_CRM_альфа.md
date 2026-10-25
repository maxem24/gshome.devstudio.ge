---
tags: [gshome, crm, access]
---

# CRM-альфа: где что и что нельзя сломать

Спецификация — `docs/superpowers/specs/2026-10-25-crm-alpha-design.md`, план —
`docs/superpowers/plans/2026-10-25-crm-alpha.md`. Решения — в [[02_Решения]] (от 25.10). Здесь — то,
что нужно помнить при работе с кодом.

## Где что

| что | где |
|---|---|
| закрепление объекта: взять, передать, снять, закрыть | `App\Crm\Pins`; модель `App\Models\ListingPin` |
| сделки, этапы, итог, «состоялась» / «не состоялась» | `App\Crm\Deals`, `App\Crm\DealStages` (+ `DealStage`); модели `Deal`, `DealStageChange` |
| показы, итог показа, перенос | `App\Crm\Viewings`; модель `Viewing`; enum `App\Enums\ViewingOutcome` |
| клиенты: поиск и заведение по телефону, перенос номера | `App\Crm\ClientLookup`, `ClientMatch`; модель `Client` |
| задачи | `App\Crm\Tasks`; модель `Task`; enum статусов — `config/crm.php` (`task_statuses`) |
| «Мои задачи»: задачи и показы без итога в одном списке | `App\Crm\Agenda`, `AgendaItem` |
| архив: что скрыто, «Показывать архивное» | `App\Crm\Archive` (`Archive::scope`) |
| журнал: единственная точка записи | `App\Crm\Journal` (`Journal::record`); чтение и ленты — `App\Crm\JournalQuery` |
| отчёт руководителя по агентам | `App\Crm\AgentReport`, `AgentReportRow` |
| демо-данные | `App\Crm\CrmDemo` |
| события и реакции на них | `App\Crm\Events\PinClosed`, `PinTransferred`; `App\Listeners\CloseDealsOnPinClosed`, `ReassignDealsOnPinTransferred` |
| исключения | `App\Crm\Exceptions\CrmForbidden`, `PinTaken` |
| права «кто кого видит» | `App\Access\OrgAccess`: `canActFor()`, `canSee()`, `handOverScope()`, `visibleUsers()` |
| политики панели | `App\Policies\DealPolicy`, `TaskPolicy` |
| модели | `App\Models\Client`, `ListingPin`, `Deal`, `DealStageChange`, `Viewing`, `Task`, `CrmSetting` |
| ресурсы панели | `App\Filament\Resources\Clients`, `Deals`, `Tasks` |
| страницы панели | `App\Filament\Pages\MyObjects`, `MyAgenda`, `JournalPage`, `AgentReportPage`, `DealStagesSettings` (только владелец), `ListingView` (кнопки «Взять в работу» и сделка) |
| лента на главной | `App\Filament\Widgets\CrmFeed` |
| уведомления | `App\Notifications\TaskAssigned`, `ViewingReminder` (колокольчик, база) |
| напоминания о показах | `App\Console\Commands\SendViewingReminders` (`crm:viewing-reminders`) |
| демо-сидер | `App\Console\Commands\CrmDemoCommand` (`gshome:crm-demo`) |
| настройки альфы | `config/crm.php`: часовой пояс, валюты, надёжность, статусы задач, `stale_days` |
| подписи | `lang/{ru,en,ka}/gshome.php`, ключи `crm.*` (грузинский — машинный перевод) |
| тесты | `tests/Feature/Crm/` |

## Правила, которые легко сломать

- **Права — только через `OrgAccess`.** Новая проверка «кто что видит или может» — в `OrgAccess`,
  ресурс или политика правил не придумывают. Для действий — `canActFor()` (человек активен и
  видим); для записей руководителя — `canSee()` (статус не проверяет, нужен для ушедших и
  заблокированных сотрудников). Передача держателем-агентом — в пределах ступени тимлида:
  `handOverScope()`.
- **Кнопка — не защита.** `->visible()` в Filament только прячет кнопку. Каждый action проверяет
  право на сервере. Сервисы проверяют сами: например, `Pins::close()` вызывает `assertCanManage()`
  и перечитывает пин под `lockForUpdate`, `Deals::lockOpen()` делает то же для сделки.
- **Ничего не удаляется.** Модели `Client`, `ListingPin`, `Deal`, `Task`, `Viewing` — `SoftDeletes`;
  «В архив» — это `delete()`. Физического удаления в коде нет. Исключения записаны в
  [[02_Решения]]: «Сохранённые» (жёсткое удаление, как было) и `gshome:crm-demo --fresh`.
- **Архив видит только владелец и только по переключателю.** `Archive::scope()` добавляет
  `withTrashed()` лишь для владельца с `show_archived`. Архивный клиент не виден другим ролям:
  поиск и перенос номера проходят через `ClientLookup::hiddenFrom()` и `reassignPhone()`.
- **Журнал — только через `App\Crm\Journal`.** Прямой `activity()` в коде вне `Journal` не пишем.
  Журнал не правится и не архивируется. Для `spatie/laravel-activitylog` задано
  `delete_records_older_than_days = 365` (`config/activitylog.php`): команду `activitylog:clean`
  **не планировать** — журнал хранится полностью.
- **Закрепление: ключ фиксируется при взятии.** `pin_key` = `c:{cluster_id}` или `l:{listing_id}`
  и не пересчитывается, даже если Real Estate перестроит группу. Одно активное закрепление на
  ключ держит база: частичный уникальный индекс `listing_pins_active_key` (`status = 'active'`,
  `deleted_at IS NULL`). Вторая сделка на один пин и клиента — индекс `deals_pin_client_unique`.
- **Закрытие закрепления.** «Снять с себя» (`released`) при открытых сделках запрещено
  (`CrmForbidden`). «Состоялась» (`won`) и «объект недоступен» (`unavailable`) закрывают открытые
  сделки по этому закреплению (`CloseDealsOnPinClosed`); при передаче открытые сделки и показы
  переходят к новому держателю (`ReassignDealsOnPinTransferred`).
- **Итог показа и этап сделки — два поля.** Не смешивать: итог меняет показ, этап меняет агент.
- **Время: в базе — `timestamptz`, сессия БД — в поясе приложения.** `config/database.php`:
  `timezone` для pgsql = `DB_TIMEZONE`, по умолчанию `APP_TIMEZONE` (`Asia/Tbilisi`). Без этого
  `timestamptz` пишется со сдвигом. Ввод и напоминания — `config('crm.timezone')`.
- **Filament: параметр замыкания — `$query`.** Filament подставляет параметры по имени; `fn ($q)`
  для `searchable`/`sortable` ломает запрос.
- **`notifications.data` — `jsonb`** (миграция `2026_10_25_220000_change_notifications_data_to_jsonb`).
  Топбар Filament читает поле через `->>`; при `text` каждая страница отдаёт 500.
- **Лента — без имени и телефона клиента.** В свойства событий журнала имя и телефон не кладём.
- **Этапы сделки** — одна запись `crm_settings` (JSON), меняет только владелец. Удаления нет:
  этап скрывают, у старых сделок он должен остаться читаемым.
- **Напоминание — сначала claim, потом отправка.** `crm:viewing-reminders` вставляет строку в
  `viewing_reminders` (`insertOrIgnore`) и шлёт уведомление только при успешной вставке. Отсюда
  «не больше одного раза» — и риск «не отправлено, но отмечено» при сбое.

## Команды

    docker compose exec app php artisan gshome:crm-demo            # демо-данные поверх людей из базы
    docker compose exec app php artisan gshome:crm-demo --fresh    # пересоздать демо одной транзакцией; сбой оставит прежнее
    docker compose exec app php artisan gshome:crm-demo --forget   # снять пометку демо, ничего не удаляя
    docker compose exec app php artisan crm:viewing-reminders      # разовый прогон напоминаний

Напоминания в расписании: `crm:viewing-reminders` каждые 5 минут (`routes/console.php`).
Локально — профиль `scheduler`: `docker compose --profile scheduler up -d scheduler`. На проде —
crontab, как для остального расписания.

`gshome:crm-demo --fresh` удаляет только записи, чьи id лежат в `crm_settings['demo']` (ключ
`CrmDemo::SETTING`), вместе с записями журнала, которые записала заливка (`['activities']`). Записи вне этого списка
и журнал настоящих людей по демо-записям не трогает. `--fresh` сначала берёт людей и объявления,
потом в одной транзакции стирает и заливает заново: любой сбой оставляет прежнее демо. Если
демо-номера (+995555100101…106) заняты не демо-клиентами, команда отказывает до стирания. Если к демо-записям
прицепились настоящие сделки или показы, стирание отказывает: их должен отсоединить или закрыть
администратор, либо `--forget` снимает пометку демо без удаления.

Архив: владелец переключает «Показывать архивное» в меню аккаунта (пункт виден только ему).
Демо на проде — отдельным шагом с подтверждением, не при деплое.
