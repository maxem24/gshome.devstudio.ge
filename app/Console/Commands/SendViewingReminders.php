<?php

namespace App\Console\Commands;

use App\Enums\DealStatus;
use App\Enums\UserStatus;
use App\Models\Viewing;
use App\Notifications\ViewingReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Напоминания агентам о показах: за 2 часа, в день показа с 09:00, просроченные без итога. */
class SendViewingReminders extends Command
{
    protected $signature = 'crm:viewing-reminders';

    protected $description = 'Напоминания агентам о показах';

    public function handle(): int
    {
        $tz = config('crm.timezone');
        $now = CarbonImmutable::now($tz);
        $horizon = max($now->endOfDay(), $now->addHours(2));

        $viewings = Viewing::query()
            ->whereNull('outcome')
            ->where('scheduled_at', '<=', $horizon)
            ->whereHas('deal', fn ($q) => $q
                ->where('status', DealStatus::Open)
                ->whereHas('agent', fn ($a) => $a->where('status', UserStatus::Active)))
            ->with('deal.agent', 'deal.pin')
            ->get();

        $sent = 0;
        foreach ($viewings as $viewing) {
            $at = CarbonImmutable::instance($viewing->scheduled_at)->setTimezone($tz);
            foreach ($this->dueKinds($now, $at) as $kind => $sentOn) {
                // Сначала запись, потом отправка: параллельный запуск вставит 0 строк и промолчит.
                $claimed = DB::table('viewing_reminders')->insertOrIgnore([
                    'viewing_id' => $viewing->id,
                    'kind' => $kind,
                    'sent_on' => $sentOn,
                ]);
                if ($claimed === 1) {
                    Notification::send($viewing->deal->agent, new ViewingReminder($viewing, $kind));
                    $sent++;
                }
            }
        }

        $this->info("Отправлено напоминаний: {$sent}");

        return self::SUCCESS;
    }

    /**
     * Какие напоминания пора слать и под какой датой их помечать.
     * before и day_of — по дню показа (одно на показ), overdue — по сегодняшнему дню.
     *
     * @return array<string, string>
     */
    private function dueKinds(CarbonImmutable $now, CarbonImmutable $at): array
    {
        $due = [];
        if ($now < $at && $now >= $at->subHours(2)) {
            $due[ViewingReminder::BEFORE] = $at->toDateString();
        }
        // Показ раньше 09:00 — «утреннего» напоминания у него нет; в окне «за 2 часа»
        // оно уже не нужно (иначе два сообщения подряд про один показ).
        $nine = $at->setTime(9, 0);
        if ($now < $at->subHours(2) && $now >= $nine && $at > $nine) {
            $due[ViewingReminder::DAY_OF] = $at->toDateString();
        }
        if ($now >= $at->addHour()) {
            $due[ViewingReminder::OVERDUE] = $now->toDateString();
        }

        return $due;
    }
}
