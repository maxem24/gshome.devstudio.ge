<?php

namespace Tests\Feature\Crm;

use App\Crm\Journal;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_writes_event_subject_causer_and_properties(): void
    {
        $user = User::factory()->owner()->create();
        $company = Company::factory()->create();

        $activity = Journal::record(Journal::PIN_TAKEN, $company, ['listing_id' => 7], $user);

        $this->assertSame(Journal::PIN_TAKEN, $activity->event);
        $this->assertSame($company->id, $activity->subject_id);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame(['listing_id' => 7], $activity->properties->toArray());
    }

    public function test_record_accepts_past_time(): void
    {
        $at = Carbon::parse('2026-09-01 12:00:00');

        $activity = Journal::record(Journal::DEAL_OPENED, null, [], null, $at)->refresh();

        $this->assertTrue($activity->created_at->equalTo($at));
        $this->assertTrue($activity->updated_at->equalTo($at));
    }

    public function test_causer_defaults_to_authenticated_user_and_may_be_absent(): void
    {
        $user = User::factory()->owner()->create();

        $this->assertNull(Journal::record(Journal::ARCHIVED, null)->causer_id);

        $this->actingAs($user);
        $this->assertSame($user->id, Journal::record(Journal::ARCHIVED, null)->causer_id);
    }
}
