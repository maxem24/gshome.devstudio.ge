<?php

namespace Tests\Feature\Livewire;

use App\Enums\Direction;
use App\Jobs\PollListingSearch;
use App\Livewire\PinnedSearches;
use App\Models\Company;
use App\Models\ListingSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class PinnedSearchesTest extends TestCase
{
    use RefreshDatabase;

    private User $nino;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        $this->nino = User::factory()->agent(User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create())->create();
    }

    /** @param  array<string, mixed>  $attrs */
    private function search(User $by, array $attrs = []): ListingSearch
    {
        return ListingSearch::query()->create($attrs + [
            'user_id' => $by->id,
            'filters' => ['deal' => 'sale', 'city_id' => 1, 'property_type_id' => 1, 'price_usd_max' => 90000],
            'body_hash' => sha1((string) $by->id.microtime()),
            'remote_id' => (string) Str::uuid(),
            'status' => 'done', 'found' => 2, 'listing_ids' => [13, 14],
            'started_at' => now()->subHours(3), 'finished_at' => now()->subHours(3),
        ]);
    }

    public function test_shows_own_pinned_only(): void
    {
        $this->search($this->nino, ['title' => 'Мой закреплённый', 'pinned_at' => now()]);
        $this->search($this->nino, ['title' => 'Просто недавний']);
        $this->search(User::factory()->create(), ['title' => 'Чужой закреплённый', 'pinned_at' => now()]);
        $this->actingAs($this->nino);

        Livewire::test(PinnedSearches::class)
            ->assertSee(__('gshome.quick_search.pinned_title'))
            ->assertSee('Мой закреплённый')
            ->assertDontSee('Просто недавний')
            ->assertDontSee('Чужой закреплённый');
    }

    public function test_repeat_starts_search(): void
    {
        Queue::fake();
        $old = $this->search($this->nino, ['pinned_at' => now()]);
        RealEstateFake::fake(searchPosts: [Http::response(['data' => RealEstateFake::searchJob()], 202)]);
        $this->actingAs($this->nino);

        Livewire::test(PinnedSearches::class)->call('searchAgain', $old->id);

        $this->assertSame(2, ListingSearch::query()->count());
        Queue::assertPushed(PollListingSearch::class);
    }

    public function test_empty_renders_nothing(): void
    {
        $this->actingAs($this->nino);

        Livewire::test(PinnedSearches::class)->assertDontSee(__('gshome.quick_search.pinned_title'));
    }
}
