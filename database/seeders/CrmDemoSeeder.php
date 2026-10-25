<?php

namespace Database\Seeders;

use App\Crm\CrmDemo;
use Illuminate\Database\Seeder;

/** Демо CRM-альфы поверх людей из базы. Повторный запуск — через `gshome:crm-demo --fresh`. */
class CrmDemoSeeder extends Seeder
{
    public function run(CrmDemo $demo): void
    {
        foreach ($demo->seed() as $line) {
            $this->command?->line($line);
        }
    }
}
