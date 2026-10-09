<?php

namespace Tests\Feature\Org;

use App\Models\Company;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_number_is_normalized_on_save(): void
    {
        $phone = Phone::factory()->create(['number' => '555 12 34 56']);

        $this->assertSame('+995555123456', $phone->refresh()->number);
    }

    public function test_same_number_in_other_writing_is_a_duplicate(): void
    {
        Phone::factory()->create(['number' => '+995555123456']);

        $this->expectException(QueryException::class);
        Phone::factory()->create(['number' => '0555 12 34 56']);
    }

    public function test_holder_must_be_from_the_same_company(): void
    {
        $lead = User::factory()->teamLead()->create();

        $this->expectException(InvalidArgumentException::class);
        Phone::factory()->create(['company_id' => Company::factory(), 'user_id' => $lead->id]);
    }

    public function test_owner_cannot_hold_a_number(): void
    {
        $owner = User::factory()->owner()->create();

        $this->expectException(InvalidArgumentException::class);
        Phone::factory()->create(['user_id' => $owner->id]);
    }

    public function test_inactive_user_cannot_receive_a_number(): void
    {
        $lead = User::factory()->teamLead()->inactive()->create();

        $this->expectException(InvalidArgumentException::class);
        Phone::factory()->heldBy($lead)->create();
    }

    public function test_moving_user_to_another_company_releases_old_company_numbers(): void
    {
        $lead = User::factory()->teamLead()->create();
        $phone = Phone::factory()->heldBy($lead)->create();
        $other = Company::factory()->create();

        $lead->update(['company_id' => $other->id]);

        $this->assertNull($phone->refresh()->user_id);
        $this->assertNotSame($other->id, $phone->company_id);
    }
}
