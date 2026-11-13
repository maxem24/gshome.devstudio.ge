<?php

namespace Tests\Feature\Livewire;

use App\Enums\Direction;
use App\Livewire\RecentSearches;
use App\Models\Company;
use App\Models\ListingSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecentSearchesTest extends TestCase
{
    use RefreshDatabase;

    private function seller(): User
    {
        $lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();

        return User::factory()->agent($lead)->create();
    }

    private function search(User $user, string $status = 'done', int $shown = 2): ListingSearch
    {
        return ListingSearch::query()->create([
            'user_id' => $user->id,
            'filters' => ['deal' => 'sale', 'city_id' => 1],
            'body_hash' => md5(uniqid('', true)),
            'status' => $status,
            'started_at' => now(),
            'listing_ids' => $status === 'done' ? range(1, $shown) : [],
        ]);
    }

    public function test_shows_three_latest_own_searches(): void
    {
        $user = $this->seller();
        $colleague = $this->seller();
        $own = [];
        for ($i = 0; $i < 4; $i++) {
            $own[] = $this->search($user);
        }
        $this->search($colleague);
        $this->actingAs($user);

        $rows = (new RecentSearches)->searches();

        $this->assertSame([$own[3]->id, $own[2]->id, $own[1]->id], $rows->pluck('id')->all());
    }

    public function test_polls_only_while_running(): void
    {
        $user = $this->seller();
        $this->actingAs($user);
        $done = $this->search($user);

        Livewire::test(RecentSearches::class)->assertDontSeeHtml('wire:poll.10s');

        $done->update(['status' => 'running']);

        Livewire::test(RecentSearches::class)->assertSeeHtml('wire:poll.10s')->assertSee(__('gshome.quick_search.recent_running'));
    }

    public function test_hidden_without_searches(): void
    {
        $this->actingAs($this->seller());

        Livewire::test(RecentSearches::class)->assertDontSee(__('gshome.quick_search.recent_title'));
    }
}
