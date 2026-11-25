<?php

namespace Tests\Feature\Crm;

use App\Enums\Direction;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingObjectSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function pin(ListingObject $object, User $user, array $extra = []): ListingPin
    {
        return ListingPin::query()->create($extra + [
            'listing_id' => $object->listing_id,
            'pin_key' => $object->object_key.':'.$user->id.':'.uniqid(),
            'direction' => Direction::Rent,
            'user_id' => $user->id,
            'object_id' => $object->id,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);
    }

    public function test_one_open_object_per_key(): void
    {
        ListingObject::factory()->create(['object_key' => 'c:7']);

        ListingObject::factory()->closed('abandoned')->create(['object_key' => 'c:7']);
        $this->assertSame(2, ListingObject::query()->where('object_key', 'c:7')->count());

        $this->expectException(UniqueConstraintViolationException::class);
        ListingObject::factory()->create(['object_key' => 'c:7']);
    }

    public function test_one_active_pin_per_agent_per_object(): void
    {
        $object = ListingObject::factory()->create();
        $first = User::factory()->create();
        $this->pin($object, $first);
        $this->pin($object, User::factory()->create());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->pin($object, $first);
    }

    public function test_closed_object_requires_reason(): void
    {
        $this->expectException(QueryException::class);
        ListingObject::factory()->create(['status' => 'closed']);
    }

    public function test_pin_unavailable_reason_only_when_unavailable(): void
    {
        $object = ListingObject::factory()->create();
        $user = User::factory()->create();
        $this->pin($object, $user, ['status' => 'closed', 'closed_reason' => 'unavailable', 'unavailable_reason' => 'fake']);

        $this->expectException(QueryException::class);
        $this->pin($object, $user, ['unavailable_reason' => 'fake']);
    }
}
