<?php

namespace Tests\Feature\Filament;

use App\Enums\Locale;
use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_locale_switches_the_panel_and_stays(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Объекты')
            ->assertSee('Инфопанель')
            ->assertSee('Русский')
            ->assertSee('English')
            ->assertSee('ქართული');

        $this->actingAs($user->fresh())
            ->post(route('locale.switch', ['locale' => 'en']))
            ->assertRedirect();

        $this->assertSame(Locale::English, $user->refresh()->locale);

        // Меню панели собирается один раз на процесс: в php-fpm это один запрос,
        // в тесте приложение живёт дальше — пересобираем его, как новый запрос.
        $this->refreshApplication();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Properties')
            ->assertSee('Dashboard')
            ->assertDontSee('Объекты');
    }

    public function test_unknown_locale_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('locale.switch', ['locale' => 'fr']))
            ->assertNotFound();
    }

    public function test_locale_survives_livewire_requests(): void
    {
        // Фильтр, кнопка и догрузка — запросы /livewire/update, они выполняют только постоянные middleware.
        $this->assertContains(SetUserLocale::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
    }
}
