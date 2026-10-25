<?php

namespace App\Console\Commands;

use App\Crm\CrmDemo;
use Illuminate\Console\Command;
use RuntimeException;

/** Заливает демо-сценарии CRM-альфы; --fresh сначала убирает прежние демо-записи (и только их). */
class CrmDemoCommand extends Command
{
    protected $signature = 'gshome:crm-demo {--fresh : удалить прежние демо-записи и создать заново}';

    protected $description = 'Demo data for the CRM alpha walkthrough';

    public function handle(CrmDemo $demo): int
    {
        if (app()->environment('production') && ! $this->confirm('Production: create CRM demo records?')) {
            return self::FAILURE;
        }

        try {
            if ($this->option('fresh')) {
                $demo->preflight();
                $demo->wipe();
            }
            foreach ($demo->seed() as $line) {
                $this->line($line);
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
