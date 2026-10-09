<?php

namespace Tests\Feature\Org;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_owner(): void
    {
        $this->artisan('gshome:owner', ['--name' => 'Max CL', '--email' => 'Max+CL@absoluteweb.com', '--password' => 'secret-pass'])
            ->assertSuccessful();

        $user = User::query()->where('email', 'max+cl@absoluteweb.com')->firstOrFail();
        $this->assertSame(Role::Owner, $user->role);
        $this->assertNull($user->company_id);
    }

    public function test_refuses_existing_email(): void
    {
        User::factory()->create(['email' => 'max+cl@absoluteweb.com']);

        $this->artisan('gshome:owner', ['--name' => 'Max CL', '--email' => 'MAX+CL@absoluteweb.com', '--password' => 'secret-pass'])
            ->assertFailed();
    }

    public function test_refuses_short_password(): void
    {
        $this->artisan('gshome:owner', ['--name' => 'Max CL', '--email' => 'max+cl@absoluteweb.com', '--password' => 'short'])
            ->assertFailed();
    }
}
