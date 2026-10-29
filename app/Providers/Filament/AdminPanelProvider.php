<?php

namespace App\Providers\Filament;

use App\Filament\LocaleMenu;
use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\SetUserLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            // Таблицы людей и номеров широкие: без этого действия уезжали за прокрутку.
            ->maxContentWidth(Width::Full)
            // Свои классы Tailwind в видах карточки и сетки: без темы их нет в CSS панели.
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->userMenuItems(LocaleMenu::items())
            // Кнопка «Найти квартиру» видна на каждом экране панели, всем ролям.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => Auth::check() ? Blade::render('@livewire(\App\Livewire\FindListing::class)') : '')
            // Рядом — «По ссылке»: ссылка myhome / ss.ge на объявление или выдачу (видна, когда Real Estate принимает ссылки).
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => Auth::check() ? Blade::render('@livewire(\App\Livewire\ImportByLink::class)') : '')
            ->brandName('JS Home')
            ->brandLogo(asset('images/brand/logo-navy.png'))
            ->darkModeBrandLogo(asset('images/brand/logo-white.png'))
            ->brandLogoHeight('3.25rem')
            ->favicon(asset('images/brand/icon-32.png'))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => Auth::check() ? Blade::render('@livewire(\App\Livewire\NotificationToasts::class)') : '')
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn (): string => Auth::check() ? view('filament.brand.back')->render() : '')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.brand.head'))
            ->renderHook(PanelsRenderHook::FOOTER, fn () => view('filament.brand.contacts'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('filament.brand.contacts'))
            // Тёмно-синий агентства; палитра Filament строится по светлоте, 400-600 читаются и в тёмной теме.
            ->colors([
                'primary' => Color::generateV3Palette('#0c1a4a'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
            ])
            // Livewire-запросы (фильтр, кнопка, догрузка) идут мимо маршрута панели и выполняют
            // только постоянные middleware: без isPersistent язык после первого клика — APP_LOCALE.
            ->middleware([SetUserLocale::class], isPersistent: true)
            ->middleware([
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
