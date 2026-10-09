# CLAUDE.md

## О проекте

**GS Home** (git `maxem24/gshome.devstudio.ge`) — CRM для группы агентств недвижимости
поверх базы **Real Estate** (`~/Sites/real-estate.devstudio.ge`, git
`maxem24/real-estate.devstudio.ge`). Свою базу объявлений GS Home не ведёт: данные
получает через API Real Estate, а у себя хранит людей и их работу — компании, команды,
роли, встречи, заметки, уведомления.

API в Real Estate пока **нет** (только `routes/web.php` и `routes/console.php`) —
его предстоит спроектировать вместе с клиентом здесь.

Образец оснастки и порядка работы — BigMed (`~/Sites/coda/bigmed.ge`).

## Документация — сначала она

Документация — `docs/GSHome/` (Obsidian, `NN_Название.md`), вход — `00_Навигатор.md`.
Планы и спецификации задач — `docs/superpowers/plans`, `docs/superpowers/specs`.

| документ | о чём |
|---|---|
| `01_ТЗ.md` | исходное ТЗ клиента |
| `02_Решения.md` | принятые решения и **TODO — открытые вопросы** |
| `03_Среда_разработки.md` | Docker, адреса, база, оснастка `bin/` |
| `04_Тестирование_и_анализ.md` | тесты, PHPStan, Pint, CI — **прочитать перед запуском тестов** |
| `05_Оргструктура.md` | роли, номера, обмен; **права — только через OrgAccess** |

Перед задачей: найти релевантный документ, сверить его с кодом, только потом менять.
Расходится документ с кодом — источник истины код, а документ предложить обновить.
Решение по архитектуре, API, бизнес-логике или правам — записать в `02_Решения.md`
(дата, что, почему, чем платим). Вопрос из TODO закрылся — перенести его в решения.

## Стек

| Компонент | Версия |
|---|---|
| PHP | 8.3 (в `composer.json` прибит `config.platform.php`) |
| Laravel | ^13 |
| Filament | ^5, панель `admin` → https://gshome.local/admin |
| База | PostgreSQL 17 (на сервере — рядом с базой Real Estate) |
| Очереди | Redis + Horizon → https://gshome.local/horizon |
| Почта (локально) | Mailpit → https://mail.gshome.local |
| Фронт | Vite 8 + Tailwind 4, Node 24 |
| Тесты | PHPUnit 12 + paratest |
| Анализ | Larastan, уровень 5, без baseline |

## Инструменты: не делай руками то, для чего есть скрипт

Просьба совпала со строкой — запусти инструмент, а не собирай команды сам.

| просьба звучит так | запусти |
|---|---|
| начало сессии, «проверь, всё ли работает» | `bin/preflight.sh` |
| «проверь перед PR», «прогони всё», «сделай PR» | `bin/ship.sh` (или `--pr "Заголовок"`) |
| «проверь по-быстрому» | `bin/ship.sh --quick` |
| «прогони тесты» | `docker compose run --rm test composer test` |
| «проверь код», «есть ли ошибки» | `docker compose exec app composer analyse` |
| «подними докер», «запусти среду» | `docker compose up -d`, затем `bin/preflight.sh` |
| «пересобери автодополнение» | `docker compose exec app composer ide-helper` |

`bin/*` лежат вне репозитория (`.gitignore`) — оснастка рабочего места. Если `bin/`
нет — скажи об этом и восстанови по BigMed, а не делай работу вручную. Ручная команда
обходит защиты скрипта (самопроверку анализатора, сверку каталога стека, порядок
«сначала дешёвое») и выглядит так же успешно.

## Среда — Docker (OrbStack)

```bash
docker compose up -d                              # app, web, horizon, postgres, redis, mailpit
docker compose exec app php artisan …             # artisan
docker compose exec app composer …                # composer — в контейнере, не MAMP-ом на хосте
docker compose run --rm test composer test        # тесты — ТОЛЬКО в сервисе test
docker compose run --rm node npm run build        # фронт
docker compose restart horizon                    # после правки джоб: Horizon держит код в памяти
docker compose --profile scheduler up -d scheduler
```

- Composer с хоста (MAMP) ставит пакеты под PHP 8.4 — только в контейнере.
- Тесты в `app` не запускать: там `DB_DATABASE=gshome`; `phpunit.xml` и `TestCase` такой
  запуск отвергают.
- Из контейнера `https://real-estate.local` доступен напрямую — API Real Estate
  локально берём оттуда (`config('services.real_estate')`).

## Порядок работы в сессии

1. **В начале — `bin/preflight.sh`.** Проверяет сервисы, базу, связь с Real Estate,
   модуль PhpStorm и **работоспособность PHPStan** (заведомо битым файлом).
   Отсутствие сигнала неотличимо от отсутствия проблем, пока инструмент не проверен
   поломкой.
2. **По ходу — PHPStan, а не сьют.** `composer analyse` после каждой пачки правок,
   точечные тесты `--filter` после правки логики, полный сьют — один раз перед PR.
3. **Данные — через PhpStorm MCP**, источник `Docker` (`127.0.0.1:5433/gshome`):
   `mcp__phpstorm__execute_sql_query`, `introspect_schema`, `preview_table_data`.
   Схему видно до того, как писать код.
4. **Перед сдачей** — инспекции PhpStorm по каждому изменённому файлу
   (`mcp__phpstorm__get_file_problems`), затем `bin/ship.sh`.

Ветки — от `main`, PR — через `bin/ship.sh --pr`.
