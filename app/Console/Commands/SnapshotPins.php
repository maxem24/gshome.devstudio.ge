<?php

namespace App\Console\Commands;

use App\Crm\Pins;
use App\Enums\Direction;
use App\Models\ListingPin;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\RealEstateClient;
use Illuminate\Console\Command;

/** Обновляет снимок объявления у объектов аренды в работе: по нему считается подбор пар. */
class SnapshotPins extends Command
{
    protected $signature = 'pins:snapshot {--stale= : только снимки старше этого числа часов}';

    protected $description = 'Обновить цену, комнаты и микрорайон объектов аренды в работе';

    public function handle(Pins $pins, RealEstateClient $client): int
    {
        $query = ListingPin::query()
            ->where('status', ListingPin::ACTIVE)
            ->where('direction', Direction::Rent);
        $stale = $this->option('stale');
        if (is_numeric($stale)) {
            $query->where(fn ($q) => $q->whereNull('snapshot_at')->orWhere('snapshot_at', '<', now()->subHours((int) $stale)));
        }

        $updated = 0;
        foreach ($query->orderBy('id')->cursor() as $pin) {
            try {
                $listing = $client->listing($pin->listing_id);
            } catch (RealEstateRateLimited $e) {
                // Квота кончилась: остальные дождутся следующего запуска, а не получат по 429.
                $this->warn("Лимит API, остановлено на {$pin->listing_id}");
                break;
            } catch (RealEstateException $e) {
                $listing = null;
                $this->warn("{$pin->listing_id}: {$e->getMessage()}");
            }
            if ($listing === null) {
                $this->warn("Объявление {$pin->listing_id} недоступно, пропущено");
            } elseif ($pins->snapshot($pin, $listing, touch: true)) {
                $updated++;
            }
            // Квота API: не больше трёх запросов в секунду.
            if (! app()->runningUnitTests()) {
                usleep(300_000);
            }
        }
        $this->info("Обновлено снимков: {$updated}");

        return self::SUCCESS;
    }
}
