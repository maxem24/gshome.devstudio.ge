<?php

namespace App\Providers;

use App\Support\BugsnagRedactor;
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
    }
}
