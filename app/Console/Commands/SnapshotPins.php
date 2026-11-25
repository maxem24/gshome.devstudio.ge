<?php

namespace App\Console\Commands;

use App\Crm\ListingObjects;
use App\Enums\Direction;
use App\Models\ListingObject;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\RealEstateClient;
use Illuminate\Console\Command;

/** Обновляет снимок объявления у открытых объектов аренды: по нему считается подбор пар. */
class SnapshotPins extends Command
{
    protected $signature = 'pins:snapshot {--stale= : только снимки старше этого числа часов}';

    protected $description = 'Обновить цену, комнаты и микрорайон открытых объектов аренды';

    public function handle(ListingObjects $objects, RealEstateClient $client): int
    {
        $query = ListingObject::query()
            ->where('status', ListingObject::OPEN)
            ->where('direction', Direction::Rent);
        $stale = $this->option('stale');
        if (is_numeric($stale)) {
            $query->where(fn ($q) => $q->whereNull('snapshot_at')->orWhere('snapshot_at', '<', now()->subHours((int) $stale)));
        }

        $updated = 0;
        // По id: объекты, склеенные по ходу, закроются, и их уже не отдадут повторно.
        foreach ($query->orderBy('id')->cursor() as $object) {
            // Склейка могла закрыть объект раньше, чем до него дошла очередь.
            if (! $object->fresh()?->isOpen()) {
                continue;
            }
            try {
                $listing = $client->listing($object->listing_id);
            } catch (RealEstateRateLimited $e) {
                // Квота кончилась: остальные дождутся следующего запуска, а не получат по 429.
                $this->warn("Лимит API, остановлено на {$object->listing_id}");
                break;
            } catch (RealEstateException $e) {
                $listing = null;
                $this->warn("{$object->listing_id}: {$e->getMessage()}");
            }
            if ($listing === null) {
                $this->warn("Объявление {$object->listing_id} недоступно, пропущено");
            } else {
                // Свежее объявление знает свою группу дублей: findOpen склеивает объекты.
                $target = $objects->findOpen($listing->id, $listing->clusterId);
                // Объект закрыли, пока шли за объявлением: пропускаем (пауза квоты ниже сохраняется).
                if ($target !== null && $objects->snapshot($target, $listing, touch: true)) {
                    $updated++;
                }
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
