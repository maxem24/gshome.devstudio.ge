<?php

namespace Tests\Feature\Crm;

use App\Crm\DaySummary;
use App\Enums\Direction;
use App\Filament\Pages\MyAgenda;
use App\Filament\Pages\MyObjects;
use App\Livewire\DayGreeting;
use App\Models\Client;
use App\Models\Company;
use App\Models\ListingPin;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class DaySummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $nino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nino = User::factory()->agent(User::factory()->teamLead(Company::factory()->create(), Direction::Rent)->create())->create(['name' => 'Нино Беридзе']);
    }

    private function at(string $tbilisi): CarbonImmutable
    {
        return CarbonImmutable::parse($tbilisi, (string) config('crm.timezone'));
    }

    private function task(string $body, ?CarbonImmutable $due, string $status = 'open'): Task
    {
        return Task::query()->create(['body' => $body, 'assignee_id' => $this->nino->id, 'author_id' => $this->nino->id, 'due_at' => $due, 'status' => $status]);
    }

    private function pin(int $listingId): ListingPin
    {
        return ListingPin::query()->create([
            'listing_id' => $listingId, 'pin_key' => 'l'.$listingId, 'direction' => Direction::Rent,
            'user_id' => $this->nino->id, 'status' => ListingPin::ACTIVE, 'last_activity_at' => now(),
        ]);
    }

    public function test_greeting_by_tbilisi_hour(): void
    {
        $s = app(DaySummary::class);
        $this->assertSame('morning', $s->for($this->nino, $this->at('2026-11-18 08:00'))['greeting']);
        $this->assertSame('day', $s->for($this->nino, $this->at('2026-11-18 13:00'))['greeting']);
        $this->assertSame('evening', $s->for($this->nino, $this->at('2026-11-18 21:00'))['greeting']);
        $this->assertSame('evening', $s->for($this->nino, $this->at('2026-11-19 00:30'))['greeting']);
    }

    public function test_counts(): void
    {
        $pins = [$this->pin(1), $this->pin(2)];
        $client = Client::factory()->create();
        // Один клиент в двух открытых сделках считается один раз; закрытая сделка не считается.
        foreach ([[$pins[0], $client->id, 'open'], [$pins[1], $client->id, 'open'], [$pins[0], Client::factory()->create()->id, 'won']] as $i => [$pin, $clientId, $status]) {
            DB::table('deals')->insert(['listing_pin_id' => $pin->id, 'client_id' => $clientId, 'user_id' => $this->nino->id,
                'stage' => 's'.$i, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->task('Позвонить', null);

        $sum = app(DaySummary::class)->for($this->nino);
        $this->assertSame(2, $sum['pins']);
        $this->assertSame(1, $sum['clients']);
        $this->assertSame(1, $sum['tasks']);
    }

    public function test_today_takes_overdue_and_today_only_max_5(): void
    {
        $now = $this->at('2026-11-18 10:00');
        $this->task('Просрочено', $this->at('2026-11-17 12:00'));
        foreach (range(1, 5) as $h) {
            $this->task('Сегодня '.$h, $this->at('2026-11-18 1'.$h.':00'));
        }
        $this->task('Завтра', $this->at('2026-11-19 09:00'));
        $this->task('Без срока', null);
        $this->task('Сделано', $this->at('2026-11-18 11:30'), 'done');

        $sum = app(DaySummary::class)->for($this->nino, $now);
        $titles = array_map(fn ($i): string => $i->title, $sum['today']);
        $this->assertSame(['Просрочено', 'Сегодня 1', 'Сегодня 2', 'Сегодня 3', 'Сегодня 4'], $titles);
        $this->assertSame(1, $sum['today_more']);
    }

    public function test_component_greets_by_first_name_with_links(): void
    {
        $this->travelTo($this->at('2026-11-18 13:00'));
        $this->pin(1);
        $this->task('Отправить договор', $this->at('2026-11-18 15:00'));
        $this->actingAs($this->nino);

        Livewire::test(DayGreeting::class)
            ->assertSee('Добрый день, Нино!')
            ->assertSee('Отправить договор')
            ->assertSeeHtml('href="'.MyObjects::getUrl().'"')
            ->assertSeeHtml('href="'.MyAgenda::getUrl().'"')
            ->assertSee('1 квартира в работе')
            ->assertSeeHtml('задача</a>.');
    }

    public function test_owner_without_work_has_no_zero_counts(): void
    {
        $this->travelTo($this->at('2026-11-18 08:00'));
        $this->actingAs(User::factory()->owner()->create(['name' => 'Гиорги']));

        Livewire::test(DayGreeting::class)
            ->assertSee('Доброе утро, Гиорги!')
            ->assertSee(__('gshome.dashboard.nothing_yet'))
            ->assertSee(__('gshome.dashboard.today_empty'))
            ->assertDontSee('0 квартир');
    }
}
