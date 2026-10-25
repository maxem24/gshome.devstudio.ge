<?php

namespace App\Crm;

use App\Console\Commands\SendViewingReminders;
use App\Enums\Direction;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Enums\ViewingOutcome;
use App\Models\Client;
use App\Models\CrmSetting;
use App\Models\Deal;
use App\Models\ListingPin;
use App\Models\Task;
use App\Models\User;
use App\Models\Viewing;
use App\RealEstate\Listing;
use App\RealEstate\ListingQuery;
use App\RealEstate\RealEstateClient;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

/**
 * Демо-данные для показа альфы руководителю: семь сценариев спецификации
 * (docs/superpowers/specs/2026-10-25-crm-alpha-design.md, «Демо-данные»).
 *
 * Всё создаётся через сервисы CRM, а «прошлое» — подменой текущего времени
 * (Carbon::setTestNow): даты в записях и в журнале совпадают сами.
 * Свои записи помнит в crm_settings['demo'] и только их удаляет при --fresh.
 */
class CrmDemo
{
    public const SETTING = 'demo';

    /** Номер клиента для сценария «конфликт номера»: на показе вводим его с другим именем. */
    public const CONFLICT_PHONE = '+995555100101';

    /** @var array{clients: list<int>, pins: list<int>, deals: list<int>, viewings: list<int>, tasks: list<int>, notifications: list<string>} */
    private array $created = ['clients' => [], 'pins' => [], 'deals' => [], 'viewings' => [], 'tasks' => [], 'notifications' => []];

    public function __construct(
        private readonly RealEstateClient $api,
        private readonly Pins $pins,
        private readonly Deals $deals,
        private readonly Viewings $viewings,
        private readonly Tasks $tasks,
        private readonly ClientLookup $clients,
    ) {}

    public function exists(): bool
    {
        return CrmSetting::query()->whereKey(self::SETTING)->exists();
    }

    /**
     * Создаёт все сценарии. Возвращает подсказки для показа: кто есть кто и где смотреть.
     *
     * @return list<string>
     */
    public function seed(): array
    {
        if ($this->exists()) {
            throw new RuntimeException('Demo data already exists; run with --fresh to recreate it.');
        }

        [$lead, $nino, $giorgi, $lead2, $agent3, $agent4] = $this->people();
        $listings = $this->listings($nino);
        $now = Carbon::now();
        $before = DB::table('notifications')->pluck('id')->all();

        try {
            $notes = DB::transaction(fn (): array => $this->scenarios($now, $lead, $nino, $giorgi, $lead2, $agent3, $agent4, $listings));
        } finally {
            Carbon::setTestNow();
        }

        // Показ «вчера без итога» сразу появляется под колокольчиком, не дожидаясь планировщика.
        Artisan::call(SendViewingReminders::class);
        $this->created['notifications'] = DB::table('notifications')->whereNotIn('id', $before)->pluck('id')->all();
        CrmSetting::query()->create(['key' => self::SETTING, 'value' => $this->created]);

        return $notes;
    }

    /** Удаляет только записи, созданные демо (и журнал по ним). Чужие данные не трогает. */
    public function wipe(): void
    {
        $setting = CrmSetting::query()->find(self::SETTING);
        if ($setting === null) {
            return;
        }

        /** @var array<string, list<int|string>> $ids */
        $ids = $setting->value;
        DB::transaction(function () use ($ids, $setting): void {
            $subjects = [
                Viewing::class => $ids['viewings'] ?? [],
                Deal::class => $ids['deals'] ?? [],
                ListingPin::class => $ids['pins'] ?? [],
                Task::class => $ids['tasks'] ?? [],
                Client::class => $ids['clients'] ?? [],
            ];
            foreach ($subjects as $class => $list) {
                Activity::query()->where('subject_type', (new $class)->getMorphClass())->whereIn('subject_id', $list)->delete();
            }
            DB::table('viewing_reminders')->whereIn('viewing_id', $ids['viewings'] ?? [])->delete();
            DB::table('notifications')->whereIn('id', $ids['notifications'] ?? [])->delete();
            Viewing::withTrashed()->whereIn('id', $ids['viewings'] ?? [])->forceDelete();
            DB::table('deal_stage_changes')->whereIn('deal_id', $ids['deals'] ?? [])->delete();
            Deal::withTrashed()->whereIn('id', $ids['deals'] ?? [])->forceDelete();
            ListingPin::withTrashed()->whereIn('id', $ids['pins'] ?? [])->forceDelete();
            Task::withTrashed()->whereIn('id', $ids['tasks'] ?? [])->forceDelete();
            Client::withTrashed()->whereIn('id', $ids['clients'] ?? [])->forceDelete();
            $setting->delete();
        });
    }

    /**
     * @param  list<Listing>  $l  [0] — с группой дублей, остальные — разные квартиры
     * @return list<string>
     */
    private function scenarios(CarbonInterface $now, User $lead, User $nino, User $giorgi, User $lead2, User $agent3, User $agent4, array $l): array
    {
        $at = function (string $shift) use ($now): CarbonInterface {
            $t = $now->copy()->modify($shift);
            Carbon::setTestNow($t);

            return $t;
        };

        // 1. Конфликт номера: «Нино Беридзе» уже в базе.
        $at('-10 days');
        $conflict = $this->client($nino, self::CONFLICT_PHONE, 'Нино', 'Беридзе', ['buyer']);

        // 2 и 4. Объект с дублями в работе у Нино; Гиорги откроет любую копию и увидит плашку.
        $at('-5 days');
        $pinGroup = $this->pin($l[0], $nino);
        $tamar = $this->client($nino, '+995555100102', 'Тамар', 'Гелашвили', ['buyer']);
        $dealGroup = $this->deal($pinGroup, $tamar, $nino);
        $this->deals->moveStage($dealGroup, 'viewing_scheduled', $nino);

        // 5. Показ вчера, итог не внесён: висит в «Моих задачах» и под колокольчиком.
        $at('-3 days');
        $this->viewing($dealGroup, $now->copy()->subDay()->setTime(15, 0), 'Показ с супругой', $nino);

        // 6. «Приходил ли агент»: задача, показ, перенос, итог — всё в журнале с датами.
        $at('-6 days 10:00');
        $davit = $this->client($nino, '+995555100103', 'Давид', 'Майсурадзе', ['buyer']);
        $callback = $this->task($nino, $nino, 'Перезвонить Давиду Майсурадзе по квартире на Палиашвили', $now->copy()->subDays(5)->setTime(12, 0));
        $pinStory = $this->pin($l[1], $nino);
        $dealStory = $this->deal($pinStory, $davit, $nino);
        $at('-5 days 11:30');
        $this->tasks->setStatus($callback, 'done', $nino);
        $this->deals->moveStage($dealStory, 'viewing_scheduled', $nino);
        $story = $this->viewing($dealStory, $now->copy()->subDays(4)->setTime(12, 0), null, $nino);
        $at('-4 days 10:00');
        $this->viewings->reschedule($story, $now->copy()->subDays(4)->setTime(17, 0), $nino);
        $at('-4 days 18:15');
        $this->viewings->recordOutcome($story, ViewingOutcome::Negotiating, $nino);
        $this->deals->moveStage($dealStory, 'viewing_done', $nino);
        $at('-2 days 13:00');
        $this->deals->moveStage($dealStory, 'negotiation', $nino);

        // 3. Зависший объект: взят 8 дней назад, движения нет.
        $at('-8 days');
        $this->pin($l[2], $agent3);

        // 7. Закрытые сделки для отчёта: состоялась, не состоялась, «объект недоступен».
        $at('-7 days');
        $pinWon = $this->pin($l[3], $giorgi);
        $dealWon = $this->deal($pinWon, $this->client($giorgi, '+995555100104', 'Ника', 'Ломидзе', ['buyer']), $giorgi);
        $at('-3 days');
        $this->deals->win($dealWon, '120000', 'USD', $giorgi);

        $at('-6 days');
        $pinLost = $this->pin($l[4], $agent4);
        $dealLost = $this->deal($pinLost, $this->client($agent4, '+995555100105', 'Мариам', 'Церетели', ['buyer']), $agent4);
        $at('-2 days');
        $this->deals->lose($dealLost, 'Клиент выбрал другой вариант', $agent4);

        $at('-5 days');
        $pinGone = $this->pin($l[5], $agent3);
        $this->deal($pinGone, $this->client($agent3, '+995555100106', 'Лука', 'Кавтарадзе', ['tenant', 'buyer']), $agent3);
        $at('-1 day');
        $this->pins->markUnavailable($pinGone, $lead2);

        // Задача от тимлида агенту — новое уведомление под колокольчиком.
        $at('-1 hour');
        $this->task($lead, $nino, 'Подготовь подборку из трёх квартир в Ваке до пятницы', $now->copy()->addDays(3)->setTime(18, 0));

        Carbon::setTestNow();
        $copy = $l[0]->clusterListings[0]['id'] ?? null;

        return [
            "Агент «Нино» — {$nino->name} ({$nino->email}), «Гиорги» — {$giorgi->name} ({$giorgi->email}), их тимлид — {$lead->name}.",
            'Конфликт номера: в «Клиенты → Создать» введите '.self::CONFLICT_PHONE." и имя «Леван Капанадзе» (в базе — {$conflict->first_name} {$conflict->last_name}).",
            "Объект с дублями в работе у Нино: объявление {$l[0]->id}".($copy !== null ? ", его копия {$copy}" : '').' — откройте под Гиорги.',
            "Зависший объект: {$l[2]->id} у {$agent3->name} — красным в отчёте.",
            "Журнал «приходил ли агент»: сделка №{$dealStory->id}, клиент Давид Майсурадзе.",
            'Показ вчера без итога — «Мои задачи» и колокольчик у Нино.',
        ];
    }

    /** @return array{0: User, 1: User, 2: User, 3: User, 4: User, 5: User} тимлид, Нино, Гиорги, второй тимлид, его два агента */
    private function people(): array
    {
        $leads = User::query()
            ->where('role', Role::TeamLead)->where('direction', Direction::Sale)->where('status', UserStatus::Active)
            ->whereNotNull('company_id')->orderBy('id')->get()
            ->filter(fn (User $l): bool => $l->agents()->where('status', UserStatus::Active)->count() >= 2)
            ->values();
        $first = $leads->first();
        $second = $first === null ? null : $leads->first(fn (User $l): bool => $l->company_id === $first->company_id && $l->isNot($first));
        if ($first === null || $second === null) {
            throw new RuntimeException('Need a company with two sale team leads, each with two active agents (see OrgDemoSeeder).');
        }

        [$nino, $giorgi] = $first->agents()->where('status', UserStatus::Active)->orderBy('id')->take(2)->get()->all();
        [$agent3, $agent4] = $second->agents()->where('status', UserStatus::Active)->orderBy('id')->take(2)->get()->all();

        return [$first, $nino, $giorgi, $second, $agent3, $agent4];
    }

    /**
     * Шесть объявлений продажи из разных групп дублей; первое — с копиями.
     *
     * @return list<Listing>
     */
    private function listings(User $viewer): array
    {
        $params = ListingQuery::params($viewer, [], null) ?? throw new RuntimeException('Real Estate query is empty for the demo agent.');
        $found = [];
        $keys = [];
        $cursor = null;
        for ($page = 0; $page < 5 && count($found) < 40; $page++) {
            $result = $this->api->listings($params, $cursor);
            foreach ($result->listings as $listing) {
                $key = $this->pins->keyFor($listing);
                if ($listing->direction() === Direction::Sale && ! isset($keys[$key]) && $this->pins->activeFor($listing) === null) {
                    $keys[$key] = true;
                    $found[] = $listing;
                }
            }
            if (($cursor = $result->nextCursor) === null) {
                break;
            }
        }

        $grouped = array_values(array_filter($found, fn (Listing $l): bool => $l->clusterId !== null && $l->clusterListings !== []));
        if ($grouped === [] || count($found) < 6) {
            throw new RuntimeException('Real Estate returned too few free sale listings (need 6, one with duplicates).');
        }
        $rest = array_values(array_filter($found, fn (Listing $l): bool => $l !== $grouped[0]));

        return [$grouped[0], ...array_slice($rest, 0, 5)];
    }

    /** @param  list<string>  $tags */
    private function client(User $by, string $phone, string $first, string $last, array $tags): Client
    {
        $match = $this->clients->match($phone, $first, $last);
        $client = $this->clients->create($match, ['first_name' => $first, 'last_name' => $last, 'tags' => $tags], $by);
        $this->created['clients'][] = $client->id;

        return $client;
    }

    private function pin(Listing $listing, User $by): ListingPin
    {
        $pin = $this->pins->take($listing, $by);
        $this->created['pins'][] = $pin->id;

        return $pin;
    }

    private function deal(ListingPin $pin, Client $client, User $by): Deal
    {
        $deal = $this->deals->open($pin, $client, $by);
        $this->created['deals'][] = $deal->id;

        return $deal;
    }

    private function viewing(Deal $deal, CarbonInterface $at, ?string $notes, User $by): Viewing
    {
        $viewing = $this->viewings->schedule($deal, $at, $notes, $by);
        $this->created['viewings'][] = $viewing->id;

        return $viewing;
    }

    private function task(User $author, User $assignee, string $body, ?CarbonInterface $due): Task
    {
        $task = $this->tasks->create($author, $assignee, $body, $due);
        $this->created['tasks'][] = $task->id;

        return $task;
    }
}
