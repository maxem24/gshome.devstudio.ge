<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Filament\Pages\Listings;
use App\Filament\Pages\SavedListings;
use App\Models\SavedListing;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class SavedListingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_keeps_a_listing_that_is_on_the_screen_and_a_second_click_removes_it(): void
    {
        RealEstateFake::fake([RealEstateFake::page([
            RealEstateFake::listing(['id' => 13, 'title' => ['ru' => 'Квартира в Ваке']]),
        ])]);
        $user = User::factory()->owner()->create();
        $this->actingAs($user);

        Livewire::test(Listings::class)
            ->assertSee('Сохранить')
            ->callAction(TestAction::make('saveForLater')->table('13'))
            ->assertSee('Сохранено');

        $this->assertDatabaseHas('saved_listings', ['user_id' => $user->id, 'listing_id' => 13]);

        Livewire::test(Listings::class)
            ->callAction(TestAction::make('saveForLater')->table('13'));

        $this->assertDatabaseMissing('saved_listings', ['user_id' => $user->id, 'listing_id' => 13]);
    }

    public function test_save_rejects_a_listing_that_is_not_on_the_screen(): void
    {
        RealEstateFake::fake([RealEstateFake::page([RealEstateFake::listing(['id' => 13])])]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(Listings::class)->call('saveListing', 999)->assertNotFound();
        $this->assertDatabaseCount('saved_listings', 0);
    }

    public function test_saved_page_shows_only_this_users_visible_listings(): void
    {
        $owner = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();
        SavedListing::query()->create(['user_id' => $owner->id, 'listing_id' => 13]);
        SavedListing::query()->create(['user_id' => $owner->id, 'listing_id' => 14]);
        SavedListing::query()->create(['user_id' => $other->id, 'listing_id' => 15]);

        RealEstateFake::fake([], [
            13 => RealEstateFake::listing(['id' => 13, 'title' => ['ru' => 'Квартира в Ваке']]),
            14 => RealEstateFake::listing([
                'id' => 14,
                'deal_type' => ['id' => 2, 'slug' => 'rent', 'title' => 'Сдаётся'],
                'title' => ['ru' => 'Аренда в Сабуртало'],
            ]),
            15 => RealEstateFake::listing(['id' => 15, 'title' => ['ru' => 'Чужое объявление']]),
        ]);
        $this->actingAs($owner);

        Livewire::test(SavedListings::class)
            ->assertSee('Квартира в Ваке')
            ->assertSee('Аренда в Сабуртало')
            ->assertDontSee('Чужое объявление');

        $this->actingAs(User::factory()->teamLead(direction: Direction::Sale)->create());
        SavedListing::query()->create(['user_id' => auth()->id(), 'listing_id' => 13]);
        SavedListing::query()->create(['user_id' => auth()->id(), 'listing_id' => 14]);

        Livewire::test(SavedListings::class)
            ->assertSee('Квартира в Ваке')
            ->assertDontSee('Аренда в Сабуртало');
    }

    public function test_missing_listing_stays_saved_but_is_not_shown(): void
    {
        $user = User::factory()->owner()->create();
        SavedListing::query()->create(['user_id' => $user->id, 'listing_id' => 13]);
        SavedListing::query()->create(['user_id' => $user->id, 'listing_id' => 99]);
        RealEstateFake::fake([], [
            13 => RealEstateFake::listing(['id' => 13, 'title' => ['ru' => 'Квартира в Ваке']]),
        ]);
        $this->actingAs($user);

        Livewire::test(SavedListings::class)->assertSee('Квартира в Ваке');
        $this->assertDatabaseHas('saved_listings', ['user_id' => $user->id, 'listing_id' => 99]);
    }

    public function test_remove_drops_only_this_users_row(): void
    {
        $user = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();
        SavedListing::query()->create(['user_id' => $user->id, 'listing_id' => 13]);
        SavedListing::query()->create(['user_id' => $other->id, 'listing_id' => 13]);
        RealEstateFake::fake([], [
            13 => RealEstateFake::listing(['id' => 13, 'title' => ['ru' => 'Квартира в Ваке']]),
        ]);
        $this->actingAs($user);

        Livewire::test(SavedListings::class)
            ->assertSee('Убрать из сохранённых')
            ->assertSeeHtml('fi-ta-content-grid')
            ->callAction(TestAction::make('removeSaved')->table('13'))
            ->assertDontSee('Квартира в Ваке');

        $this->assertDatabaseMissing('saved_listings', ['user_id' => $user->id, 'listing_id' => 13]);
        $this->assertDatabaseHas('saved_listings', ['user_id' => $other->id, 'listing_id' => 13]);
    }

    public function test_saved_page_shows_the_fifty_newest(): void
    {
        $user = User::factory()->owner()->create();
        $cards = [];
        foreach (range(1, 51) as $id) {
            SavedListing::query()->create(['user_id' => $user->id, 'listing_id' => $id]);
            $cards[$id] = RealEstateFake::listing([
                'id' => $id,
                'title' => ['ru' => $id === 1 ? 'Старое объявление' : "Объект {$id}"],
            ]);
        }
        RealEstateFake::fake([], $cards);
        $this->actingAs($user);

        Livewire::test(SavedListings::class)
            ->assertSee('Показаны 50 последних сохранённых объявлений.')
            ->assertSee('Объект 51')
            ->assertDontSee('Старое объявление');
    }

    public function test_new_login_does_not_forget_saved_listings(): void
    {
        $user = User::factory()->owner()->create();
        SavedListing::query()->create(['user_id' => $user->id, 'listing_id' => 13]);

        Auth::guard('web')->login($user);

        $this->assertDatabaseHas('saved_listings', ['user_id' => $user->id, 'listing_id' => 13]);
    }

    public function test_saved_page_is_a_menu_item(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        $this->get('/admin/saved-listings')->assertOk()->assertSee('Сохранённые');
        $this->get('/admin/listings')->assertOk()->assertSee('Сохранённые');
    }
}
