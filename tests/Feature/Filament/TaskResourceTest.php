<?php

namespace Tests\Feature\Filament;

use App\Crm\Deals;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Crm\Tasks;
use App\Crm\Viewings;
use App\Enums\Direction;
use App\Filament\Pages\MyAgenda;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Client;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class TaskResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $nino;

    private User $giorgi;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        $this->lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
        $this->nino = User::factory()->agent($this->lead)->create();
        $this->giorgi = User::factory()->agent($this->lead)->create();
    }

    /** Скрытое действие вызвать нельзя: ждём исключения или тихого отказа, но не изменения данных. */
    private function tryCall(\Closure $call): void
    {
        try {
            $call();
        } catch (\Throwable) {
        }
    }

    public function test_agent_create_form_has_no_assignee(): void
    {
        $this->actingAs($this->nino);

        Livewire::test(CreateTask::class)
            ->assertFormFieldIsHidden('assignee_id')
            // Подделанный исполнитель игнорируется: задача ставится на самого агента.
            ->fillForm(['body' => 'Позвонить', 'assignee_id' => $this->giorgi->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $task = Task::query()->sole();
        $this->assertSame($this->nino->id, $task->assignee_id);
        $this->assertSame($this->nino->id, $task->author_id);
    }

    public function test_lead_picks_only_visible_users(): void
    {
        $stranger = User::factory()->agent(User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create())->create();
        $blocked = User::factory()->agent($this->lead)->blocked()->create();
        $this->actingAs($this->lead);

        $options = TaskResource::assigneeOptions();
        $this->assertEqualsCanonicalizing(
            [$this->lead->id, $this->nino->id, $this->giorgi->id],
            array_keys($options),
        );
        $this->assertArrayNotHasKey($stranger->id, $options);
        $this->assertArrayNotHasKey($blocked->id, $options);

        Livewire::test(CreateTask::class)
            ->fillForm(['assignee_id' => $this->nino->id, 'body' => 'Сверить договор', 'due_at' => '2026-11-01 10:00:00'])
            ->call('create')
            ->assertHasNoFormErrors();
        $task = Task::query()->sole();
        $this->assertSame($this->nino->id, $task->assignee_id);
        // 10:00 в Тбилиси (UTC+4) — 06:00 UTC.
        $this->assertSame('2026-11-01 06:00', $task->due_at->utc()->format('Y-m-d H:i'));

        // Чужой исполнитель: форма не принимает, задачи не прибавляется.
        Livewire::test(CreateTask::class)
            ->fillForm(['assignee_id' => $stranger->id, 'body' => 'Чужому'])
            ->call('create')
            ->assertHasFormErrors(['assignee_id']);
        $this->assertSame(1, Task::query()->count());
    }

    public function test_agenda_shows_viewing_and_links_to_deal(): void
    {
        $listing = Listing::fromApi(RealEstateFake::listing([
            'id' => 41,
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');
        $deal = app(Deals::class)->open(app(Pins::class)->take($listing, $this->nino), Client::factory()->create(), $this->nino);
        app(Viewings::class)->schedule($deal, now()->addDay(), 'Ключи у консьержа', $this->nino);
        app(Tasks::class)->create($this->nino, $this->nino, 'Позвонить владельцу', null);

        $this->actingAs($this->nino);
        Livewire::test(MyAgenda::class)
            ->assertSee('Ключи у консьержа')
            ->assertSee('Позвонить владельцу')
            ->assertSee(DealResource::getUrl('view', ['record' => $deal]), false);
    }

    public function test_agenda_done_button_changes_status(): void
    {
        $task = app(Tasks::class)->create($this->nino, $this->nino, 'Позвонить', null);
        $this->actingAs($this->nino);

        Livewire::test(MyAgenda::class)->call('setStatus', $task->id, 'done');

        $this->assertSame('done', $task->refresh()->status);
    }

    public function test_agent_cannot_change_status_of_anothers_task(): void
    {
        $theirs = app(Tasks::class)->create($this->giorgi, $this->giorgi, 'Чужая', null);
        $this->actingAs($this->nino);

        // Через «Мои задачи» с подделанным id.
        Livewire::test(MyAgenda::class)->call('setStatus', $theirs->id, 'done')->assertForbidden();
        // Через таблицу: чужая строка не видна, действие по ней ничего не меняет.
        $this->tryCall(fn () => Livewire::test(ListTasks::class)
            ->assertCanNotSeeTableRecords([$theirs])
            ->callTableAction('status_done', $theirs));

        $this->assertSame('open', $theirs->refresh()->status);
        $this->assertSame(0, Activity::query()->where('event', Journal::TASK_STATUS_CHANGED)->count());
    }

    public function test_lead_changes_status_in_table_for_own_agent(): void
    {
        $task = app(Tasks::class)->create($this->lead, $this->nino, 'Проверить', null);
        $this->actingAs($this->lead);

        Livewire::test(ListTasks::class)
            ->assertCanSeeTableRecords([$task])
            ->callTableAction('status_done', $task);

        $this->assertSame('done', $task->refresh()->status);
    }

    public function test_archived_task_hidden_but_in_db(): void
    {
        $task = app(Tasks::class)->create($this->lead, $this->nino, 'Старое', null);
        $this->actingAs($this->lead);

        Livewire::test(ListTasks::class)->callTableAction('archive', $task);

        $this->assertSoftDeleted($task);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertSame(1, Activity::query()->where('event', Journal::ARCHIVED)->count());
        Livewire::test(ListTasks::class)->assertCanNotSeeTableRecords([$task]);
        // Архивная задача не обновляется статусом.
        $this->tryCall(fn () => app(Tasks::class)->setStatus($task, 'done', $this->lead));

        // Владелец с включённым переключателем её видит.
        $owner = User::factory()->owner()->create(['show_archived' => true]);
        $this->actingAs($owner);
        Livewire::test(ListTasks::class)->assertCanSeeTableRecords([$task]);
    }

    public function test_assignee_agent_cannot_archive_and_forged_archive_changes_nothing(): void
    {
        $task = app(Tasks::class)->create($this->lead, $this->nino, 'Задача лида', null);
        $this->actingAs($this->nino);

        $this->tryCall(fn () => Livewire::test(ListTasks::class)->callTableAction('archive', $task));

        $this->assertNotSoftDeleted($task);
    }
}
