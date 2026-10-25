<?php

namespace Tests\Feature\Crm;

use App\Crm\ClientLookup;
use App\Crm\Journal;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ClientLookupTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+995555123456';

    private function client(array $attrs = []): Client
    {
        return Client::factory()->create($attrs + [
            'phone_e164' => self::PHONE, 'first_name' => 'Нино', 'last_name' => 'Беридзе',
        ]);
    }

    public function test_new_phone_is_new(): void
    {
        $m = ClientLookup::match('0555123456', 'Нино', 'Беридзе');

        $this->assertSame('new', $m->kind);
        $this->assertNull($m->existing);
        $this->assertSame(self::PHONE, $m->phoneE164);
    }

    public function test_same_phone_same_name_any_case_is_same(): void
    {
        $this->client(['first_name' => 'Нино Беридзе', 'last_name' => null]);
        $m = ClientLookup::match(self::PHONE, 'нино  беридзе', null);
        $this->assertSame('same', $m->kind);

        $client = Client::query()->where('phone_e164', self::PHONE)->first();
        $client->update(['first_name' => 'Нино', 'last_name' => 'Беридзе']);
        $m = ClientLookup::match(self::PHONE, ' НИНО ', ' беридзе ');
        $this->assertSame('same', $m->kind);
        $this->assertTrue($m->existing->is($client));
    }

    public function test_same_phone_without_last_name_is_same(): void
    {
        $this->client(['last_name' => null]);

        $this->assertSame('same', ClientLookup::match(self::PHONE, 'Нино', 'Кто-то')->kind);
        $this->assertSame('same', ClientLookup::match(self::PHONE, 'Нино', null)->kind);
    }

    public function test_same_phone_other_name_is_conflict(): void
    {
        $client = $this->client();

        $m = ClientLookup::match(self::PHONE, 'Нино', 'Другая');
        $this->assertSame('conflict', $m->kind);
        $this->assertTrue($m->existing->is($client));

        $this->assertSame('conflict', ClientLookup::match(self::PHONE, 'Гиорги', 'Беридзе')->kind);
    }

    public function test_phone_formats_resolve_to_one_client(): void
    {
        $client = $this->client();

        foreach (['555 12 34 56', '0555123456', '+995555123456'] as $raw) {
            $m = ClientLookup::match($raw, 'Нино', 'Беридзе');
            $this->assertSame('same', $m->kind, $raw);
            $this->assertTrue($m->existing->is($client), $raw);
        }
    }

    public function test_garbage_phone_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientLookup::match('abc', 'Нино', null);
    }

    public function test_create_saves_client_and_journals(): void
    {
        $by = User::factory()->owner()->create();
        $m = ClientLookup::match('555 12 34 56', 'Нино', 'Беридзе');

        $client = ClientLookup::create($m, ['first_name' => 'Нино', 'last_name' => 'Беридзе', 'tags' => ['buyer']], $by);

        $this->assertSame(self::PHONE, $client->phone_e164);
        $this->assertSame($by->id, $client->created_by);
        $this->assertSame(['buyer'], $client->fresh()->tags);
        $this->assertSame('unverified', $client->fresh()->reliability);
        $this->assertSame(1, Activity::query()->where('event', Journal::CLIENT_CREATED)->count());
    }

    public function test_create_refuses_non_new_match(): void
    {
        $this->client();
        $m = ClientLookup::match(self::PHONE, 'Нино', 'Беридзе');

        $this->expectException(InvalidArgumentException::class);

        ClientLookup::create($m, ['first_name' => 'Нино'], User::factory()->owner()->create());
    }

    public function test_reassign_moves_phone_and_keeps_old_client_history(): void
    {
        $by = User::factory()->owner()->create();
        $old = $this->client();
        $m = ClientLookup::match(self::PHONE, 'Мария', 'Кикнадзе');
        $this->assertSame('conflict', $m->kind);

        $new = ClientLookup::reassignPhone($m, ['first_name' => 'Мария', 'last_name' => 'Кикнадзе'], $by);

        $this->assertNull($old->fresh()->phone_e164);
        $this->assertSame(self::PHONE, $new->phone_e164);
        $this->assertNotSame($old->id, $new->id);

        $records = Activity::query()->where('event', Journal::CLIENT_PHONE_REASSIGNED)->get();
        $this->assertCount(2, $records);
        $this->assertEqualsCanonicalizing([$old->id, $new->id], $records->pluck('subject_id')->all());
        foreach ($records as $r) {
            $this->assertSame($old->id, $r->properties['old_client_id']);
            $this->assertSame($new->id, $r->properties['new_client_id']);
        }
    }

    public function test_reassign_refuses_non_conflict(): void
    {
        $this->client();
        $m = ClientLookup::match(self::PHONE, 'Нино', 'Беридзе');

        $this->expectException(InvalidArgumentException::class);

        ClientLookup::reassignPhone($m, ['first_name' => 'Нино'], User::factory()->owner()->create());
    }

    public function test_archived_client_still_holds_phone(): void
    {
        $this->client()->delete();
        $agent = User::factory()->agent(User::factory()->teamLead()->create())->create();

        $m = ClientLookup::match(self::PHONE, 'Другой', 'Человек');
        $this->assertSame('conflict', $m->kind);
        $this->assertNotNull($m->existing->deleted_at);

        // Прямая вставка второго с тем же номером упирается в уникальный индекс.
        $this->expectException(QueryException::class);
        Client::factory()->create(['phone_e164' => self::PHONE, 'created_by' => $agent->id]);
    }
}
