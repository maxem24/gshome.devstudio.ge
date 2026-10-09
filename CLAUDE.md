# CLAUDE.md

## О проекте

**GS Home** — рабочее место сотрудников поверх базы **Real Estate**
(`~/Sites/real-estate.devstudio.ge`, git `maxem24/real-estate.devstudio.ge`).
Свою базу объявлений GS Home не ведёт: данные получает и меняет через API Real Estate,
а у себя хранит сотрудников, их сессии и всё, что относится к их работе.

API в Real Estate пока **нет** (только `routes/web.php` и `routes/console.php`) —
его предстоит спроектировать вместе с клиентом здесь.

Образцы для всего, что касается среды и порядка работы:
- Real Estate — Laravel 13 + Filament 5, PostgreSQL 17, Docker на OrbStack;
- BigMed (`~/Sites/coda/bigmed.ge`) — тот же Docker-подход, Larastan, ide-helper.

## Стек

| Компонент | Версия |
|---|---|
| PHP | 8.3 (в `composer.json` прибит `config.platform.php`) |
| Laravel | ^13 |
| Filament | ^5, панель `admin` → https://gshome.local/admin |
| База | PostgreSQL 17 |
| Очереди | Redis + Horizon → https://gshome.local/horizon |
| Почта (локально) | Mailpit → https://mail.gshome.local |
| Фронт | Vite 8 + Tailwind 4, Node 24 |
| Тесты | PHPUnit 12 |
| Анализ | Larastan, уровень 5 |

PHP прибит к 8.3, как у Real Estate и BigMed: без этого Composer на маке ставит
Symfony 8, которому нужен PHP 8.4, и контейнер падает на `platform_check.php`.

## Среда — Docker (OrbStack)

```bash
docker compose up -d                              # app, web, horizon, postgres, redis, mailpit
docker compose exec app php artisan …             # artisan
docker compose exec app composer …                # composer — в контейнере, не MAMP-ом на хосте
docker compose run --rm test php artisan test     # тесты — ТОЛЬКО в сервисе test
docker compose exec app composer analyse          # PHPStan
docker compose exec app composer ide-helper       # хелперы PhpStorm
docker compose run --rm node npm run build        # фронт
docker compose restart horizon                    # после правки джоб: Horizon держит код в памяти
docker compose --profile scheduler up -d scheduler
```

- База с хоста (PhpStorm): `127.0.0.1:5433`, `gshome/gshome`, база `gshome`.
  5432 занят Postgres Real Estate, 3307 — MariaDB BigMed.
- Тестовая база `gshome_test` создаётся при первом старте тома
  (`docker/postgres/init`). Тесты в `app` не запускать: там `DB_DATABASE=gshome`,
  `phpunit.xml` (`force="true"`) и `tests/TestCase.php` такой запуск отвергают.
- Из контейнера `https://real-estate.local` доступен напрямую — API Real Estate
  локально берём оттуда (`REAL_ESTATE_API_URL`, `config('services.real_estate')`).

## Перед сдачей работы

1. `docker compose run --rm test php artisan test`
2. `docker compose exec app composer analyse`
3. Инспекции PhpStorm по каждому изменённому файлу (`mcp__phpstorm__get_file_problems`).
4. `vendor/bin/pint --dirty`
