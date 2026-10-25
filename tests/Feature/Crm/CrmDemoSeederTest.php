<?php

namespace Tests\Feature\Crm;

use App\Crm\CrmDemo;
use App\Crm\Deals;
use App\Crm\Journal;
use App\Enums\DealStatus;
use App\Models\Client;
use App\Models\Deal;
use App\Models\ListingPin;
use App\Models\Task;
use App\Models\User;
use App\Models\Viewing;
use App\Notifications\TaskAssigned;
use Carbon\Carbon;
use Database\Seeders\OrgDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class CrmDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OrgDemoSeeder::class);
        $listings = [
            RealEstateFake::listing(['id' => 101, 'cluster' => ['id' => 9, 'listings_count' => 2, 'phones_e164' => [], 'listings' => [
                ['id' => 102, 'source' => 'myhome', 'is_primary' => false, 'seller' => ['is_owner' => true, 'is_agency' => false, 'agency_name' => null], 'contact' => ['phone_e164' => null]],
            ]]]),
        ];
        foreach ([103, 104, 105, 106, 107, 108] as $id) {
            $listings[] = RealEstateFake::listing(['id' => $id]);
        }
        RealEstateFake::fake([RealEstateFake::page($listings)]);
    }

    public function test_creates_all_seven_scenarios(): void
    {
        $notes = app(CrmDemo::class)->seed();

        $this->assertNotEmpty($notes);
        $this->assertSame(1, Client::query()->where('phone_e164', CrmDemo::CONFLICT_PHONE)->where('first_name', 'Нино')->count());

        $group = ListingPin::query()->where('pin_key', 'c:9')->where('status', 'active')->sole();
        $this->assertSame(101, $group->listing_id);

        $this->assertSame(1, Viewing::query()->whereNull('outcome')->where('scheduled_at', '<', now())->count());
        $this->assertSame(1, Activity::query()->where('event', Journal::VIEWING_RESCHEDULED)->count());
        $this->assertSame(1, Activity::query()->where('event', Journal::VIEWING_OUTCOME)->count());
        $this->assertSame(1, Activity::query()->where('event', Journal::TASK_STATUS_CHANGED)->count());

        $this->assertSame(1, Deal::query()->where('status', DealStatus::Won)->where('amount', 120000)->count());
        $this->assertSame(1, Deal::query()->where('status', DealStatus::Lost)->count());
        $this->assertSame(1, Deal::query()->where('status', DealStatus::Unavailable)->count());
        $this->assertSame(2, Task::query()->count());

        // Нино получила задачу от тимлида и напоминание о вчерашнем показе.
        $nino = Deal::query()->where('listing_pin_id', $group->id)->sole()->agent;
        $this->assertSame(2, $nino->notifications()->count());
    }

    public function test_stale_pin_is_eight_days_old_in_journal_and_report(): void
    {
        app(CrmDemo::class)->seed();

        $stale = ListingPin::query()->where('status', 'active')->where('last_activity_at', '<', now()->subDays(7))->sole();
        $taken = Activity::query()->where('event', Journal::PIN_TAKEN)->where('subject_id', $stale->id)->sole();
        $this->assertSame(8, (int) round($taken->created_at->diffInDays(now(), true)));
        $this->assertSame(8, (int) round($stale->last_activity_at->diffInDays(now(), true)));
        $this->assertNull(Carbon::getTestNow());
    }

    public function test_rerun_with_fresh_does_not_duplicate(): void
    {
        $demo = app(CrmDemo::class);
        $demo->seed();
        $counts = [Client::withTrashed()->count(), ListingPin::withTrashed()->count(), Deal::withTrashed()->count(), Activity::query()->count()];

        $this->expectExceptionMessage('already exists');
        try {
            $demo->seed();
        } finally {
            $demo->wipe();
            $this->assertSame([0, 0, 0], [Client::withTrashed()->count(), ListingPin::withTrashed()->count(), Deal::withTrashed()->count()]);
            app(CrmDemo::class)->seed();
            $this->assertSame($counts, [Client::withTrashed()->count(), ListingPin::withTrashed()->count(), Deal::withTrashed()->count(), Activity::query()->count()]);
        }
    }

    public function test_does_not_touch_non_demo_records(): void
    {
        $owner = User::factory()->owner()->create();
        $mine = Client::query()->create(['phone_e164' => '+995599000001', 'first_name' => 'Чужой', 'tags' => ['buyer'], 'reliability' => 'unverified', 'created_by' => $owner->id]);

        $demo = app(CrmDemo::class);
        $demo->seed();
        $demo->wipe();

        $this->assertTrue($mine->fresh()?->exists);
        $this->assertSame(1, Client::withTrashed()->count());
    }

    public function test_command_refuses_without_fresh_and_recreates_with_it(): void
    {
        $this->artisan('gshome:crm-demo')->assertSuccessful();
        $this->artisan('gshome:crm-demo')->assertFailed();
        $this->artisan('gshome:crm-demo', ['--fresh' => true])->assertSuccessful();
        $this->assertSame(6, ListingPin::query()->count());
    }

    public function test_real_reminders_and_notifications_survive_seed_and_wipe(): void
    {
        $realLead = User::factory()->teamLead()->create();
        $realAgent = User::factory()->agent($realLead)->create();
        $realAgent->notify(new TaskAssigned(
            Task::query()->create(['body' => 'Настоящая задача', 'assignee_id' => $realAgent->id, 'author_id' => $realLead->id, 'status' => 'open']),
            $realLead,
        ));

        $demo = app(CrmDemo::class);
        $demo->seed();
        $demo->wipe();

        $this->assertSame(1, $realAgent->notifications()->count());
        $this->assertSame(1, Task::query()->count());
        $this->assertSame(0, DB::table('viewing_reminders')->count());
    }

    public function test_wipe_refuses_when_real_deal_is_attached_to_demo_pin(): void
    {
        $demo = app(CrmDemo::class);
        $demo->seed();
        $pin = ListingPin::query()->where('pin_key', 'c:9')->sole();
        $real = Client::query()->create(['phone_e164' => '+995599000002', 'first_name' => 'Реальный', 'tags' => ['buyer'], 'reliability' => 'unverified', 'created_by' => $pin->user_id]);
        app(Deals::class)->open($pin, $real, $pin->holder);

        try {
            $demo->wipe();
            $this->fail('wipe should refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('non-demo', $e->getMessage());
        }
        $this->assertTrue($demo->exists());
        $this->assertSame(1, ListingPin::query()->whereKey($pin->id)->count());
    }
}
