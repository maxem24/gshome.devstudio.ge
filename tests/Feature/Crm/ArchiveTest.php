<?php

namespace Tests\Feature\Crm;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_with_toggle_sees_trashed_others_do_not(): void
    {
        Client::factory()->create();
        Client::factory()->create()->delete();

        $owner = User::factory()->owner()->create(['show_archived' => true]);
        $this->assertCount(2, Client::query()->visibleTo($owner)->get());

        $owner->show_archived = false;
        $this->assertCount(1, Client::query()->visibleTo($owner)->get());

        $agent = User::factory()->agent(User::factory()->teamLead()->create())->create(['show_archived' => true]);
        $this->assertCount(1, Client::query()->visibleTo($agent)->get());
    }
}
