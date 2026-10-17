<?php

namespace App\Providers;

use App\Filament\Pages\Listings;
use App\Support\BugsnagRedactor;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // redacted_keys сверяет только имена ключей; значения внутри текста SQL и Livewire-снимков чистит колбэк.
        $this->callAfterResolving('bugsnag', fn ($client) => $client->registerCallback(new BugsnagRedactor));

        // regenerate(true) при входе уничтожает старый id, но оставляет данные в памяти запроса.
        Event::listen(Login::class, fn () => Listings::forgetRememberedFilters());
    }
}
