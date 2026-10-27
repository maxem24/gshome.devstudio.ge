<?php

namespace App\Console\Commands;

use App\Search\SiteSearches;
use Illuminate\Console\Command;

/** Закрывает поиски по сайтам, которые «идут» дольше срока: опрос мог пропасть вместе с очередью. */
class SweepSiteSearches extends Command
{
    protected $signature = 'search:sweep';

    protected $description = 'Закрыть зависшие поиски по сайтам';

    public function handle(SiteSearches $searches): int
    {
        $this->info('Закрыто поисков: '.$searches->sweep());

        return self::SUCCESS;
    }
}
