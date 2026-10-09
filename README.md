# GS Home

Рабочее место сотрудников на Laravel 13 + Filament 5. Данные объявлений берёт через API
проекта Real Estate (real-estate.devstudio.ge).

## Запуск локально (OrbStack)

```bash
cp .env.example .env
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose run --rm node sh -c "npm install && npm run build"
docker compose exec app php artisan make:filament-user
```

- Панель: https://gshome.local/admin
- Horizon: https://gshome.local/horizon
- Почта: https://mail.gshome.local

Пошагово для нового разработчика (включая заливку дампа) — `docs/GSHome/06_Развертывание.md`.
Подробности и порядок работы — в `CLAUDE.md`.
