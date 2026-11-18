<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Filament\Pages\MySearches;
use App\Livewire\RecentSearches;
use App\Models\Company;
use App\Models\ListingSearch;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class MySearchesManageTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $nino;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
        $this->lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
        $this->nino = User::factory()->agent($this->lead)->create();
    }

    /** @param  array<string, mixed>  $attrs */
    private function search(User $by, array $attrs = []): ListingSearch
    {
        return ListingSearch::query()->create($attrs + [
            'user_id' => $by->id,
            'filters' => ['deal' => 'sale', 'city_id' => 1, 'property_type_id' => 1, 'price_usd_max' => 90000],
            'body_hash' => sha1((string) $by->id.microtime()),
            'remote_id' => (string) Str::uuid(),
            'status' => 'done',
            'found' => 2,
            'listing_ids' => [13, 14],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function test_author_renames_and_empty_resets(): void
    {
        $s = $this->search($this->nino);
        $this->actingAs($this->nino);

        Livewire::test(MySearches::class)
            ->callAction(TestAction::make('rename')->table($s), data: ['title' => '  Ваке до $600 для семьи  '])
            ->assertHasNoActionErrors()
            ->assertSee('Ваке до $600 для семьи');
        $s->refresh();
        $this->assertSame('Ваке до $600 для семьи', $s->title);
        $this->assertSame('Ваке до $600 для семьи', $s->label());

        Livewire::test(MySearches::class)->callAction(TestAction::make('rename')->table($s), data: ['title' => '']);
        $this->assertNull($s->refresh()->title);
    }

    public function test_pinned_goes_first(): void
    {
        $old = $this->search($this->nino);
        $new = $this->search($this->nino);
        $this->actingAs($this->nino);

        Livewire::test(MySearches::class)
            ->assertCanSeeTableRecords([$new, $old], inOrder: true)
            ->callAction(TestAction::make('pin')->table($old))
            ->assertCanSeeTableRecords([$old, $new], inOrder: true)
            ->assertActionVisible(TestAction::make('unpin')->table($old))
            ->callAction(TestAction::make('unpin')->table($old))
            ->assertCanSeeTableRecords([$new, $old], inOrder: true);
    }

    public function test_author_deletes_and_it_disappears_everywhere(): void
    {
        $s = $this->search($this->nino, ['title' => 'Удаляемый', 'pinned_at' => now()]);
        $this->actingAs($this->nino);

        Livewire::test(MySearches::class)
            ->callAction(TestAction::make('delete')->table($s))
            ->assertCanNotSeeTableRecords([$s]);

        $this->assertSoftDeleted($s);
        Livewire::test(RecentSearches::class)->assertDontSee('Удаляемый');
    }

    public function test_running_search_cannot_be_deleted(): void
    {
        $s = $this->search($this->nino, ['status' => 'running', 'finished_at' => null]);
        $this->actingAs($this->nino);

        Livewire::test(MySearches::class)
            ->assertActionHidden(TestAction::make('delete')->table($s))
            ->call('deleteSearch', $s->id);

        $this->assertNotSoftDeleted($s);
    }

    public function test_leader_sees_no_manage_actions_on_agent_search(): void
    {
        $s = $this->search($this->nino, ['pinned_at' => now()]);
        $this->actingAs($this->lead);

        Livewire::test(MySearches::class)
            ->assertCanSeeTableRecords([$s])
            ->assertActionHidden(TestAction::make('rename')->table($s))
            ->assertActionHidden(TestAction::make('unpin')->table($s))
            ->assertActionHidden(TestAction::make('delete')->table($s))
            ->call('deleteSearch', $s->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted($s);
    }

    public function test_recent_searches_show_title(): void
    {
        $this->search($this->nino, ['title' => 'Мой любимый']);
        $this->actingAs($this->nino);

        Livewire::test(RecentSearches::class)->assertSee('Мой любимый');
    }
}
