<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Filament\Pages\ListingView;
use App\Models\Phone;
use App\Models\SavedListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
    }

    public function test_url_and_route(): void
    {
        $this->assertStringEndsWith('/admin/listings/13', ListingView::getUrl(['listing' => 13]));
    }

    public function test_card_shows_details_and_our_employee(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $phone = Phone::factory()->heldBy($agent)->create()->number;
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing(['contact' => ['phone_e164' => $phone]])]);
        $this->actingAs($lead);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee('Квартира в Ваке')
            ->assertSee('$90 000')
            ->assertSee('Наш сотрудник')
            ->assertSee('Нино')
            ->assertSee('https://home.ss.ge/13', escape: false)
            ->assertActionVisible('contactOwn')
            ->assertActionHidden('contactGroup');
    }

    public function test_other_company_has_no_contact_action(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee('Другая компания')
            ->assertActionHidden('contactOwn');
    }

    public function test_group_label_and_duplicates_list(): void
    {
        $lead = User::factory()->teamLead()->create();
        $agent = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $phone = Phone::factory()->heldBy($agent)->create()->number;
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing(['cluster' => [
            'id' => 5, 'listings_count' => 2, 'phones_e164' => ['+995555123456', $phone],
            'listings' => [['id' => 55, 'source' => 'ss_ge', 'source_url' => 'https://home.ss.ge/55', 'is_primary' => false,
                'seller' => ['is_owner' => false, 'is_agency' => false, 'agency_name' => null],
                'contact' => ['name' => 'Нино', 'phone' => '555', 'phone_e164' => $phone, 'has_viber' => false, 'has_whatsapp' => false],
                'price' => ['usd' => 89000.0, 'gel' => 240300.0], 'updated_at' => '2026-10-07T10:00:00+00:00']],
        ]])]);
        $this->actingAs($lead);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee('В группе есть наш сотрудник: Нино')
            ->assertSee('https://home.ss.ge/55', escape: false)
            ->assertActionVisible('contactGroup');
    }

    public function test_other_direction_is_404(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]); // продажа
        $this->actingAs(User::factory()->teamLead(direction: Direction::Rent)->create());

        $this->get('/admin/listings/13')->assertNotFound();
    }

    public function test_daily_rent_and_pledge_are_404_even_for_owner(): void
    {
        RealEstateFake::fake(cards: [
            13 => RealEstateFake::listing(['deal_type' => ['id' => 3, 'slug' => 'daily_rent']]),
            14 => RealEstateFake::listing(['id' => 14, 'deal_type' => ['id' => 4, 'slug' => 'pledge']]),
        ]);
        $this->actingAs(User::factory()->owner()->create());

        $this->get('/admin/listings/13')->assertNotFound();
        $this->get('/admin/listings/14')->assertNotFound();
    }

    public function test_missing_listing_is_404(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        $this->get('/admin/listings/999')->assertNotFound();
    }

    public function test_garbage_id_is_404_without_api_request(): void
    {
        RealEstateFake::fake();
        $this->actingAs(User::factory()->owner()->create());

        foreach (['-5', 'abc', '0', '1.5'] as $id) {
            $this->get('/admin/listings/'.$id)->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_response_without_data_renders_message_not_500(): void
    {
        Http::fake(['*/dictionaries/*' => Http::response(['data' => RealEstateFake::dealTypes()]), '*' => Http::response(['data' => null])]);
        $this->actingAs(User::factory()->owner()->create());

        $this->get('/admin/listings/13')->assertOk()->assertSee('Real Estate недоступен, попробуйте позже.');
    }

    public function test_card_state_is_locked(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $this->actingAs(User::factory()->teamLead()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(ListingView::class, ['listing' => 13])->set('card.own.can_contact', true);
    }

    public function test_api_error_renders_message_not_500(): void
    {
        RealEstateFake::configure();
        Http::fake(['*/dictionaries/*' => Http::response(['data' => RealEstateFake::dealTypes()]), '*' => Http::response([], 503)]);
        $this->actingAs(User::factory()->owner()->create());

        $this->get('/admin/listings/13')->assertOk()->assertSee('Real Estate недоступен, попробуйте позже.');
    }

    public function test_card_saves_for_later_and_removes_only_this_users_row(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $user = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();
        SavedListing::query()->create(['user_id' => $other->id, 'listing_id' => 13]);
        $this->actingAs($user);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertActionVisible('saveForLater')
            ->assertSee('Сохранить')
            ->callAction('saveForLater')
            ->assertSee('Убрать из сохранённых');

        $this->assertDatabaseHas('saved_listings', ['user_id' => $user->id, 'listing_id' => 13]);
        $this->assertDatabaseHas('saved_listings', ['user_id' => $other->id, 'listing_id' => 13]);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('saveForLater')
            ->assertSee('Сохранить');

        $this->assertDatabaseMissing('saved_listings', ['user_id' => $user->id, 'listing_id' => 13]);
        $this->assertDatabaseHas('saved_listings', ['user_id' => $other->id, 'listing_id' => 13]);
    }
}
