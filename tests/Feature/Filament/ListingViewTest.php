<?php

namespace Tests\Feature\Filament;

use App\Enums\Direction;
use App\Filament\Pages\ListingView;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
