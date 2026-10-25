<?php

namespace Tests\Feature\Crm;

use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Journal;
use App\Crm\Tasks;
use App\Enums\Direction;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $lead;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->lead = User::factory()->teamLead($this->company, Direction::Sale)->create();
        $this->agent = User::factory()->agent($this->lead)->create();
    }

    public function test_agent_creates_only_for_self(): void
    {
        $colleague = User::factory()->agent($this->lead)->create();

        $task = app(Tasks::class)->create($this->agent, $this->agent, 'Позвонить', now()->addDay());
        $this->assertSame('open', $task->status);
        $this->assertSame(1, Activity::query()->where('event', Journal::TASK_CREATED)->count());
        $this->assertSame(0, $this->agent->notifications()->count(), 'себе — без уведомления');

        $this->expectException(CrmForbidden::class);
        app(Tasks::class)->create($this->agent, $colleague, 'Чужому', null);
    }

    public function test_lead_assigns_to_own_agent_and_notifies(): void
    {
        $task = app(Tasks::class)->create($this->lead, $this->agent, 'Подготовить договор', now()->addDay());

        $this->assertSame($this->agent->id, $task->assignee_id);
        $this->assertSame($this->lead->id, $task->author_id);
        $this->assertSame(1, $this->agent->notifications()->count());
        $n = $this->agent->notifications()->first();
        $this->assertSame(TaskAssigned::class, $n->type);
        $this->assertSame('Подготовить договор', $n->data['body']);
        $this->assertStringContainsString($this->lead->name, $n->data['title']);
    }

    public function test_lead_cannot_assign_to_other_leads_agent(): void
    {
        $other = User::factory()->teamLead($this->company, Direction::Sale)->create();
        $foreign = User::factory()->agent($other)->create();

        try {
            app(Tasks::class)->create($this->lead, $foreign, 'Не моё', null);
            $this->fail('Ожидали CrmForbidden');
        } catch (CrmForbidden) {
            $this->assertSame(0, Task::query()->count());
            $this->assertSame(0, $foreign->notifications()->count());
        }
    }

    public function test_company_owner_assigns_to_lead_of_own_company(): void
    {
        $owner = User::factory()->companyOwner($this->company)->create();

        $task = app(Tasks::class)->create($owner, $this->lead, 'Отчёт за месяц', null);

        $this->assertSame($this->lead->id, $task->assignee_id);
        $this->assertSame(1, $this->lead->notifications()->count());
    }

    public function test_status_must_be_from_config(): void
    {
        $task = app(Tasks::class)->create($this->agent, $this->agent, 'Позвонить', null);

        try {
            app(Tasks::class)->setStatus($task, 'archived', $this->agent);
            $this->fail('Ожидали InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->assertSame('open', $task->fresh()->status);
        }

        app(Tasks::class)->setStatus($task, 'done', $this->agent);
        $this->assertSame('done', $task->fresh()->status);
        app(Tasks::class)->setStatus($task, 'open', $this->agent);
        $this->assertSame('open', $task->fresh()->status);
        $this->assertSame(2, Activity::query()->where('event', Journal::TASK_STATUS_CHANGED)->count());
    }

    public function test_status_rights_and_archived_assignee_handled_by_lead(): void
    {
        $task = app(Tasks::class)->create($this->lead, $this->agent, 'Задача', null);
        $stranger = User::factory()->agent($this->lead)->create();

        try {
            app(Tasks::class)->setStatus($task, 'done', $stranger);
            $this->fail('Ожидали CrmForbidden');
        } catch (CrmForbidden) {
        }

        $this->agent->update(['status' => 'archived']);
        app(Tasks::class)->setStatus($task->fresh(), 'cancelled', $this->lead);
        $this->assertSame('cancelled', $task->fresh()->status);
    }

    public function test_visible_to_follows_ladder(): void
    {
        $tasks = app(Tasks::class);
        $mine = $tasks->create($this->agent, $this->agent, 'Моя', null);
        $other = User::factory()->teamLead($this->company, Direction::Sale)->create();
        $theirs = $tasks->create($other, $other, 'Чужая', null);

        $this->assertEquals([$mine->id], Task::query()->visibleTo($this->agent)->pluck('id')->all());
        $this->assertEquals([$mine->id], Task::query()->visibleTo($this->lead)->pluck('id')->all());
        $this->assertCount(2, Task::query()->visibleTo(User::factory()->owner()->create())->get());
        $this->assertNotNull($theirs);
    }
}
