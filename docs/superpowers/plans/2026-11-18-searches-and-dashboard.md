# Мои поиски и живая инфопанель — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** автор может переименовать, закрепить и удалить свой поиск; на инфопанели появляются приветствие со сводкой дня и блок «Закреплённые поиски».

**Architecture:** две колонки в `listing_searches`; повтор поиска выносится из `MySearches` в трейт, который используют страница и новый Livewire-блок; сводку дня считает `App\Crm\DaySummary`, рисует Livewire-блок `DayGreeting` над быстрым поиском.

**Tech Stack:** Laravel 13, Filament 5, Livewire, PostgreSQL 17, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-11-18-searches-and-dashboard-design.md`

## Global Constraints

- Переименовать / закрепить / удалить — только автор (`user_id === viewer`), проверка на сервере.
- `title` — не длиннее 80 символов, пустая строка → `null`.
- Идущий поиск (`ListingSearch::RUNNING`) удалить нельзя.
- Время суток по `config('crm.timezone')`: 05:00–11:59 утро, 12:00–17:59 день, иначе вечер.
- Тексты — во все три `lang/{ru,en,ka}/gshome.php`.
- Тесты — `docker compose run --rm test …`; после пачки — `composer analyse`; перед сдачей — инспекции PhpStorm, `bin/ship.sh`.

## Review Focus

1. Удалённый поиск больше не виден ни в «Моих поисках», ни в «Недавних», ни в «Закреплённых» (тест в Task 1).
2. Поиск закрепил агент, а руководитель смотрит «Мои поиски»: кнопок нет, порядок не ломается (тест в Task 1).
3. Вечером после полуночи (00:30) — «Добрый вечер», а не «Доброе утро» (тест в Task 3).
4. У владельца группы нет объектов и сделок: нет «0 квартир», нет 500 (тест в Task 3).
5. Задача без срока в «На сегодня» не попадает (тест в Task 3).

---

### Task 1: Переименовать, закрепить, удалить

**Files:** Create `database/migrations/2026_11_18_140000_add_title_pin_to_listing_searches.php`, `app/Filament/Pages/Concerns/RepeatsSearches.php`; Modify `app/Models/ListingSearch.php` (`label(?User $viewer = null): string` — `title` или `FiltersSummary::make`), `app/Filament/Pages/MySearches.php`, `resources/views/livewire/recent-searches.blade.php`, `lang/*`; Test `tests/Feature/Filament/MySearchesManageTest.php`.

**Interfaces:**
- Produces: колонки `title varchar(80) null`, `pinned_at timestampTz null`; `ListingSearch::label(?User $viewer = null): string`; scope-сортировка `pinned_at DESC NULLS LAST, id DESC`.
- Produces: трейт `RepeatsSearches` с `public function searchAgain(int $id): void` (перенос из `MySearches` без изменения поведения), требует `protected function viewer(): User`.
- Действия таблицы: `rename` (поле `title`), `pin`, `unpin`, `delete` — видимы при `user_id === viewer`; `delete` — ещё и не `RUNNING`, с подтверждением.

- [ ] Тесты: `test_author_renames_and_empty_resets`, `test_pinned_goes_first`, `test_author_deletes_and_it_disappears_everywhere` (таблица и `RecentSearches`), `test_running_search_cannot_be_deleted`, `test_leader_sees_no_manage_actions_on_agent_search`, `test_search_again_still_works` (существующие тесты повтора остаются зелёными).
- [ ] RED → реализация → GREEN, `composer analyse`, commit `feat(searches): rename, pin, delete`.

### Task 2: «Закреплённые поиски» на инфопанели

**Files:** Create `app/Livewire/PinnedSearches.php`, `resources/views/livewire/pinned-searches.blade.php`; Modify `resources/views/filament/pages/dashboard.blade.php`, `lang/*`; Test `tests/Feature/Livewire/PinnedSearchesTest.php`.

**Interfaces:** Consumes `RepeatsSearches::searchAgain`, `ListingSearch::label`. Список: свои закреплённые, `pinned_at DESC`, до 10; нет закреплённых — пустой `<div>`.

- [ ] Тесты: `test_shows_own_pinned_only` (чужие и незакреплённые не видны), `test_repeat_starts_search` (`SiteSearches::start` вызван; через существующий фейк API), `test_empty_renders_nothing`.
- [ ] RED → реализация → GREEN, commit `feat(dashboard): pinned searches`.

### Task 3: Приветствие и сводка дня

**Files:** Create `app/Crm/DaySummary.php`, `app/Livewire/DayGreeting.php`, `resources/views/livewire/day-greeting.blade.php`; Modify `dashboard.blade.php`, `lang/*` (`dashboard.greeting.{morning,day,evening}`, `dashboard.phrases.0..4`, счётчики через `trans_choice` для ru/ka/en); Test `tests/Feature/Crm/DaySummaryTest.php`, `tests/Feature/Livewire/DayGreetingTest.php`.

**Interfaces:** `DaySummary::for(User $user, ?CarbonInterface $now = null): array{greeting: string, phrase: string, pins: int, clients: int, tasks: int, today: list<AgendaItem>, today_more: int}`.
- `pins` — активные `listing_pins.user_id = user`; `clients` — `COUNT(DISTINCT client_id)` открытых сделок `user_id = user`; `tasks` — `Agenda::for($user)->count()`.
- `today` — элементы `Agenda` с `at` ≤ конец сегодняшнего дня (Тбилиси), по времени, первые 5; `today_more` — сколько ещё.

- [ ] Тесты: `test_greeting_by_tbilisi_hour` (08:00 / 13:00 / 21:00 / 00:30), `test_counts`, `test_today_takes_overdue_and_today_only_max_5`, `test_owner_without_work_has_no_zero_counts` (компонент: нет «0»), `test_links_point_to_my_objects_deals_agenda`.
- [ ] RED → реализация → GREEN, визуально на `gshome.localhost:8088` (ru, ka; 375 и 1440), commit `feat(dashboard): greeting and day summary`; `docs/GSHome/02_Решения.md` — запись; `bin/ship.sh`.
