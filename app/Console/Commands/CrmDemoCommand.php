<?php

namespace App\Console\Commands;

use App\Crm\CrmDemo;
use Illuminate\Console\Command;
use Throwable;

/** Заливает демо-сценарии CRM-альфы; --fresh сначала убирает прежние демо-записи (и только их). */
class CrmDemoCommand extends Command
{
    protected $signature = 'gshome:crm-demo {--fresh : удалить прежние демо-записи и создать заново}
        {--forget : снять пометку демо, ничего не удаляя}';

    protected $description = 'Demo data for the CRM alpha walkthrough';

    public function handle(CrmDemo $demo): int
    {
        if (app()->environment('production') && ! $this->confirm('Production: create CRM demo records?')) {
            return self::FAILURE;
        }

        try {
            if ($this->option('forget')) {
                $this->line($demo->forget() ? 'Demo marker removed; records are kept.' : 'No demo marker.');

                return self::SUCCESS;
            }
            $lines = $this->option('fresh') ? $demo->refresh() : $demo->seed();
            foreach ($lines as $line) {
                $this->line($line);
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
