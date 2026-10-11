<?php

namespace App\Support;

use Bugsnag\Report;
use Illuminate\Database\QueryException;
use ReflectionProperty;
use Throwable;

/**
 * Колбэк Bugsnag перед отправкой: убирает персональные данные, которые
 * redacted_keys не достаёт — он сверяет только имена ключей метаданных.
 *
 * - QueryException: Laravel подставляет значения в текст SQL, Postgres добавляет
 *   «DETAIL: Key (number)=(…)». Текст всей цепочки заменяем на код + SQL
 *   с плейсхолдерами и пересобираем отчёт из того же исключения (стек сохраняется).
 *   Файловый лог пишется раньше и остаётся полным — для локальной отладки.
 * - Livewire: в request.params лежат components[].snapshot (JSON-строка всей
 *   формы) и updates["data.number"] — выбрасываем целиком.
 */
class BugsnagRedactor
{
    public function __invoke(Report $report): void
    {
        $error = $report->getOriginalError();
        if ($error instanceof QueryException) {
            $this->redactQueryException($error);
            $report->setPHPThrowable($error);
        }

        $meta = $report->getMetaData();
        if (isset($meta['request']['params']) && is_array($meta['request']['params'])) {
            $params = $meta['request']['params'];
            unset($params['components'], $params['snapshot'], $params['updates']);
            $meta['request']['params'] = $params;
            $report->setMetaData($meta, false);
        }
    }

    private function redactQueryException(QueryException $e): void
    {
        $code = $e->getCode();
        $this->setMessage($e, "SQLSTATE[{$code}] (значения скрыты) SQL: {$e->getSql()}");

        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            $this->setMessage($previous, "SQLSTATE[{$code}] (значения скрыты)");
        }
    }

    private function setMessage(Throwable $e, string $message): void
    {
        $property = new ReflectionProperty($e instanceof \Exception ? \Exception::class : \Error::class, 'message');
        $property->setValue($e, $message);
    }
}
