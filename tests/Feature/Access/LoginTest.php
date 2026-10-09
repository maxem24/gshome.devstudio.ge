<?php

namespace Tests\Feature\Access;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_with_capital_letters_logs_in(): void
    {
        $user = User::factory()->companyOwner()->create(['email' => 'giorgi@gshome.ge']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'Giorgi@GSHome.ge', 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    /** Интерфейс по-русски: без lang/ru пользователь видел бы сырой ключ «validation.email». */
    public function test_validation_messages_are_translated(): void
    {
        $errors = Livewire::test(Login::class)
            ->fillForm(['email' => 'not-an-email', 'password' => 'x'])
            ->call('authenticate')
            ->errors();

        $this->assertStringNotContainsString('validation.', (string) $errors->first('data.email'));
        $this->assertStringContainsString('электронным адресом', (string) $errors->first('data.email'));
    }

    public function test_panel_uses_normalizing_login_page(): void
    {
        $this->assertSame(Login::class, Filament::getPanel('admin')->getLoginRouteAction());
    }
}
