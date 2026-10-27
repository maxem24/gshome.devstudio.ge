<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Напоминания о показах: зависят от окон в 2 часа и 1 час, пять минут — достаточная точность.
Schedule::command('crm:viewing-reminders')->everyFiveMinutes();

// Поиски по сайтам, у которых оборвался опрос (рестарт Horizon, потеря задания): закрываем по таймауту.
Schedule::command('search:sweep')->everyFiveMinutes();
