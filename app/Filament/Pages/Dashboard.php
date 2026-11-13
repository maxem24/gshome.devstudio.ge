<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/** Главная без виджетов: быстрый поиск квартиры (виджет CrmFeed остаётся в коде, но не показывается). */
class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.dashboard';

    /** @return array<class-string, mixed> */
    public function getWidgets(): array
    {
        return [];
    }
}
