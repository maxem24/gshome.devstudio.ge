<?php

namespace Tests\Unit;

use App\Support\BugsnagRedactor;
use Bugsnag\Middleware\CallbackBridge;
use Bugsnag\Report;
use Illuminate\Database\QueryException;
use PDOException;
use Tests\TestCase;

class BugsnagRedactorTest extends TestCase
{
    private const PHONE = '+995555123456';

    private function payload(Report $report): string
    {
        return (string) json_encode($report->toArray(), JSON_UNESCAPED_UNICODE);
    }

    private function report(\Throwable $e): Report
    {
        return Report::fromPHPThrowable(app('bugsnag')->getConfig(), $e);
    }

    public function test_sql_error_does_not_carry_bound_values(): void
    {
        $pdo = new PDOException('SQLSTATE[23505]: Unique violation: DETAIL: Key (number)=('.self::PHONE.') already exists.');
        $e = new QueryException('pgsql', 'insert into "phones" ("number") values (?)', [self::PHONE], $pdo);
        $report = $this->report($e);
        $this->assertStringContainsString(self::PHONE, $this->payload($report), 'precondition: raw report leaks');

        (new BugsnagRedactor)($report);

        $payload = $this->payload($report);
        $this->assertStringNotContainsString(self::PHONE, $payload);
        $this->assertStringContainsString('insert into', $payload, 'SQL shape stays for debugging');
    }

    public function test_livewire_payload_is_dropped(): void
    {
        $report = $this->report(new \RuntimeException('boom'));
        $report->setMetaData(['request' => ['params' => [
            'components' => [['snapshot' => '{"data":{"number":"'.self::PHONE.'"}}', 'updates' => ['data.number' => self::PHONE]]],
        ]]]);

        (new BugsnagRedactor)($report);

        $this->assertStringNotContainsString(self::PHONE, $this->payload($report));
    }

    public function test_redactor_is_registered_on_the_client(): void
    {
        $pipes = (fn () => $this->pipes)->call(app('bugsnag')->getPipeline());
        $callbacks = array_map(
            fn ($pipe) => $pipe instanceof CallbackBridge ? (fn () => $this->callback)->call($pipe) : $pipe,
            $pipes,
        );

        $this->assertNotEmpty(array_filter($callbacks, fn ($c) => $c instanceof BugsnagRedactor));
    }
}
