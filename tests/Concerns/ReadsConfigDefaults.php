<?php

namespace Tests\Concerns;

trait ReadsConfigDefaults
{
    /**
     * Значение конфига при НЕзаданной переменной окружения — независимо от .env
     * машины (на сервере переменная задана, и тест «по умолчанию» краснел бы).
     */
    protected function configDefault(string $file, string $key, string $envVar): mixed
    {
        $saved = [getenv($envVar), $_ENV[$envVar] ?? null, $_SERVER[$envVar] ?? null];
        putenv($envVar);
        unset($_ENV[$envVar], $_SERVER[$envVar]);

        try {
            return data_get(require config_path("{$file}.php"), $key);
        } finally {
            if ($saved[0] !== false) {
                putenv("{$envVar}={$saved[0]}");
            }
            if ($saved[1] !== null) {
                $_ENV[$envVar] = $saved[1];
            }
            if ($saved[2] !== null) {
                $_SERVER[$envVar] = $saved[2];
            }
        }
    }
}
