<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Log;
use Tests\Concerns\ReadsConfigDefaults;
use Tests\TestCase;

class BugsnagConfigTest extends TestCase
{
    use ReadsConfigDefaults;

    /** Пустая переменная в пакете значит «слать отовсюду» — локальные ошибки ушли бы в Bugsnag. */
    public function test_notifies_only_from_production_by_default(): void
    {
        $this->assertSame(['production'], $this->configDefault('bugsnag', 'notify_release_stages', 'BUGSNAG_NOTIFY_RELEASE_STAGES'));
    }

    /** Номера сотрудников, их имена и email, контакты из объявлений — персональные данные. */
    public function test_personal_data_is_always_redacted(): void
    {
        foreach (['password', 'password_confirmation', 'number', '/phone/i', '/email/i', '/contact/i'] as $key) {
            $this->assertContains($key, config('bugsnag.redacted_keys'));
        }
    }

    public function test_sql_bindings_are_not_sent(): void
    {
        $this->assertFalse(config('bugsnag.bindings'));
    }

    /** У bugsnag-laravel нет автообнаружения: без ручной регистрации провайдера канал не создаётся. */
    public function test_bugsnag_log_channel_resolves(): void
    {
        $this->assertNotNull(Log::channel('bugsnag'));
        $this->assertTrue($this->app->bound('bugsnag'));
    }
}
