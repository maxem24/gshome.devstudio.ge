<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use Bugsnag\BugsnagLaravel\BugsnagServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
    // У bugsnag-laravel нет автообнаружения (extra.laravel) — регистрируем сами.
    BugsnagServiceProvider::class,
];
