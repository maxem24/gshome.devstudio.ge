<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // До parent::setUp(): RefreshDatabase делает migrate:fresh внутри него,
        // и проверка после него опоздала бы — рабочая база уже стёрта.
        // Первый слой — force="true" в phpunit.xml; это второй, на случай его правки.
        // Тот же порядок, что у Laravel Env: $_SERVER, затем $_ENV, затем getenv().
        $database = (string) ($_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE'));
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Тесты запущены на базе «{$database}». Запускай: docker compose run --rm test php artisan test");
        }

        parent::setUp();
    }
}
