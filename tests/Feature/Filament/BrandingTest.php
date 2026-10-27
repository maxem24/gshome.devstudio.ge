<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_admin(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_login_page_shows_logo_and_phones(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('images/brand/logo-navy.png', false)
            ->assertSee('href="tel:+995577608626"', false)
            ->assertSee('href="tel:+995511288500"', false);
    }

    public function test_panel_page_has_icons_manifest_and_footer_phones(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->get('/admin')
            ->assertOk()
            ->assertSee('images/brand/icon-32.png', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('window.history.back()', false)
            ->assertSee('manifest.webmanifest', false)
            ->assertSee('href="tel:+995577608626"', false)
            ->assertSee('href="tel:+995511288500"', false);
    }
}
