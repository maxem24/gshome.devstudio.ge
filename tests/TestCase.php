<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->assertSafeTestRuntimeBeforeBootstrap();

        parent::setUp();

        // Ни один тест не ходит в сеть: настоящий вызов API Real Estate медленный
        // и зависит от чужого стека. Нужен HTTP — Http::fake().
        Http::preventStrayRequests();
    }

    /**
     * До parent::setUp(): RefreshDatabase делает migrate:fresh внутри него,
     * и проверка после него опоздала бы — рабочая база уже стёрта.
     * Первый слой — force="true" в phpunit.xml; это второй, на случай его правки.
     */
    protected function assertSafeTestRuntimeBeforeBootstrap(): void
    {
        // С закешированным конфигом Laravel не читает <env> из phpunit.xml,
        // и сьют пошёл бы в рабочую базу.
        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Тесты с закешированным конфигом не запускаются: php artisan config:clear');
        }

        // Тот же порядок, что у Laravel Env: $_SERVER, затем $_ENV, затем getenv().
        $database = (string) ($_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE'));
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Тесты запущены на базе «{$database}». Запускай: docker compose run --rm test composer test");
        }
    }
}
