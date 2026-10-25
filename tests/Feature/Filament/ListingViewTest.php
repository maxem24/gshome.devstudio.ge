<?php

namespace Tests\Feature\Filament;

use App\Crm\Deals;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Enums\DealStatus;
use App\Enums\Direction;
use App\Filament\Pages\ListingView;
use App\Models\Client;
use App\Models\ListingPin;
use App\Models\Phone;
use App\Models\SavedListing;
use App\Models\User;
use App\RealEstate\Listing;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
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

    public function test_listing_view_is_recorded(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $this->actingAs($lead);

        Livewire::test(ListingView::class, ['listing' => 13]);

        $a = Activity::query()->where('event', Journal::LISTING_VIEWED)->sole();
        $this->assertSame(13, $a->properties['listing_id']);
        $this->assertSame($lead->id, $a->causer_id);
        $this->assertNull($a->subject_id);
    }

    public function test_listing_view_not_recorded_for_foreign_direction(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $this->actingAs(User::factory()->teamLead(null, Direction::Rent)->create());

        try {
            Livewire::test(ListingView::class, ['listing' => 13]);
        } catch (\Throwable) {
        }

        $this->assertSame(0, Activity::query()->where('event', Journal::LISTING_VIEWED)->count());
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
            ->assertSee('В нашей системе')
            ->assertSee('На сайте-источнике')
            ->assertSee('listings/55', escape: false)
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

    public function test_detail_splits_characteristics_and_marks_missing_extras(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing([
            'bedrooms' => 2,
            'amenities' => [['id' => 1, 'slug' => 'elevator', 'title' => 'Лифт']],
        ])]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee('Комнаты')
            ->assertSee('65 м²')
            ->assertSee('5/9')
            ->assertSee('Квартира')
            ->assertSee('Дополнительно')
            ->assertSee('Спальни: 2')
            ->assertSee('Санузлы')
            ->assertSee('Лифт')
            ->assertSee('Газ')
            ->assertSeeHtml('text-green-600')
            ->assertSeeHtml('text-gray-400');
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

    private function pinFor(User $by, int $id = 13, ?int $cluster = null): ListingPin
    {
        $l = Listing::fromApi(RealEstateFake::listing(['id' => $id, 'cluster' => $cluster === null ? null
            : ['id' => $cluster, 'listings_count' => 2, 'phones_e164' => [], 'listings' => []]]), 'ru');

        return app(Pins::class)->take($l, $by);
    }

    public function test_take_shows_in_work_banner(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $this->actingAs($nino);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertActionVisible('takeInWork')
            ->assertActionHidden('transferPin')
            ->callAction('takeInWork')
            ->assertNotified(__('gshome.crm.pins.taken'))
            ->assertSee(__('gshome.crm.pins.in_work_mine'))
            ->assertActionHidden('takeInWork')
            ->assertActionVisible('transferPin')
            ->assertActionVisible('releasePin');

        $this->assertSame($nino->id, ListingPin::query()->sole()->user_id);
    }

    public function test_other_agent_sees_banner_and_no_take_action(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create(['name' => 'Нино']);
        $this->pinFor($nino);

        // Гиорги из другой компании группы: имя Нино ему не раскрывается.
        $giorgi = User::factory()->agent(User::factory()->teamLead()->create())->create(['name' => 'Гиорги']);
        $this->actingAs($giorgi);
        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee(__('gshome.crm.pins.in_work_other'))
            ->assertDontSee('Нино')
            ->assertActionHidden('takeInWork')
            ->assertActionHidden('transferPin')
            ->assertActionHidden('openDeal')
            ->assertActionHidden('releasePin');

        // Владелец группы видит имя.
        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(ListingView::class, ['listing' => 13])->assertSee('В работе у Нино');
    }

    public function test_colleague_of_same_company_sees_holder_name(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $this->pinFor($nino);

        $this->actingAs(User::factory()->agent($lead)->create());
        Livewire::test(ListingView::class, ['listing' => 13])->assertSee('В работе у Нино');
    }

    public function test_take_race_shows_already_taken(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create();
        $giorgi = User::factory()->agent($lead)->create();
        $this->actingAs($giorgi);

        $page = Livewire::test(ListingView::class, ['listing' => 13]);
        $this->pinFor($nino); // Нино успела между открытием и кликом
        // Кнопка на устаревшей странице: сервер перепроверяет видимость и второго закрепления не создаёт.
        $page->call('$refresh')->assertSee(__('gshome.crm.pins.in_work_by', ['name' => $nino->name]))->assertActionHidden('takeInWork');

        $this->assertSame(1, ListingPin::query()->count());
    }

    public function test_duplicate_copy_shows_same_banner(): void
    {
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $this->pinFor($nino, 13, cluster: 5);
        RealEstateFake::fake(cards: [14 => RealEstateFake::listing(['id' => 14, 'cluster' => [
            'id' => 5, 'listings_count' => 2, 'phones_e164' => [], 'listings' => [],
        ]])]);
        $this->actingAs($lead);

        Livewire::test(ListingView::class, ['listing' => 14])
            ->assertSee('В работе у Нино')
            ->assertActionHidden('takeInWork');
    }

    public function test_transfer_offers_only_allowed_recipients(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $giorgi = User::factory()->agent($lead)->create(['name' => 'Гиорги']);
        $stranger = User::factory()->agent(User::factory()->teamLead()->create())->create(['name' => 'Чужой']);
        $this->pinFor($nino);
        $this->actingAs($lead);

        $page = Livewire::test(ListingView::class, ['listing' => 13])
            ->mountAction('transferPin')
            ->assertSee('Гиорги')
            ->assertDontSee('Чужой');
        $page->setActionData(['to' => $giorgi->id])->callMountedAction()->assertNotified(__('gshome.crm.pins.transferred'));

        $this->assertSame($giorgi->id, ListingPin::query()->sole()->user_id);
        $this->assertNotNull($stranger->id);
    }

    public function test_release_blocked_by_open_deal_then_works(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create();
        $pin = $this->pinFor($nino);
        $deal = app(Deals::class)->open($pin, Client::factory()->create(), $nino);
        $this->actingAs($nino);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('releasePin')
            ->assertNotified(__('gshome.crm.pins.release_open_deals'));
        $this->assertTrue($pin->refresh()->isActive());

        $deal->update(['status' => DealStatus::Lost]);
        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('releasePin')
            ->assertActionVisible('takeInWork');
        $this->assertFalse($pin->refresh()->isActive());
        $this->assertSame('released', $pin->closed_reason);
    }

    public function test_mark_unavailable_closes_pin(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $pin = $this->pinFor($nino);
        $this->actingAs($nino);

        Livewire::test(ListingView::class, ['listing' => 13])->callAction('markUnavailable');

        $this->assertSame('unavailable', $pin->refresh()->closed_reason);
    }

    public function test_open_deal_creates_client_and_deal(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $pin = $this->pinFor($nino);
        $this->actingAs($nino);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('openDeal', ['phone' => '555 12 34 56', 'first_name' => 'Гиви', 'last_name' => 'Беридзе'])
            ->assertNotified(__('gshome.crm.pins.deal_opened'));

        $client = Client::query()->where('phone_e164', '+995555123456')->sole();
        $this->assertSame($client->id, $pin->deals()->sole()->client_id);
    }

    public function test_open_deal_reuses_same_client_and_rejects_duplicate(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $pin = $this->pinFor($nino);
        $client = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Гиви', 'last_name' => null]);
        $this->actingAs($nino);
        $data = ['phone' => '555123456', 'first_name' => 'Гиви', 'last_name' => ''];

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('openDeal', $data)
            ->assertNotified(__('gshome.crm.pins.deal_opened'))
            ->callAction('openDeal', $data)
            ->assertNotified(__('gshome.crm.pins.deal_forbidden'));

        $this->assertSame(1, Client::query()->count());
        $this->assertSame($client->id, $pin->deals()->sole()->client_id);
    }

    public function test_open_deal_conflict_same_person_and_new_person(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $pin = $this->pinFor($nino);
        $old = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Гиви', 'last_name' => 'Лордкипанидзе']);
        $this->actingAs($nino);
        $data = ['phone' => '555123456', 'first_name' => 'Нино', 'last_name' => 'Беридзе'];

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('openDeal', $data)
            ->assertActionMounted('resolveDealConflict')
            ->callAction(TestAction::make('samePersonDeal'), [])
            ->assertNotified(__('gshome.crm.pins.deal_opened'));
        $this->assertSame($old->id, $pin->deals()->sole()->client_id);
        $this->assertSame(1, Client::query()->count());

    }

    public function test_open_deal_conflict_new_person_reassigns_number(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $old = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Гиви', 'last_name' => 'Лордкипанидзе']);
        $data = ['phone' => '555123456', 'first_name' => 'Нино', 'last_name' => 'Беридзе'];
        $pin2 = $this->pinFor($nino);
        $this->actingAs($nino);
        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('openDeal', $data)
            ->assertActionMounted('resolveDealConflict')
            ->callMountedAction()
            ->assertNotified(__('gshome.crm.pins.deal_opened'));
        $new = Client::query()->where('phone_e164', '+995555123456')->sole();
        $this->assertSame('Нино', $new->first_name);
        $this->assertNull($old->refresh()->phone_e164);
        $this->assertSame($new->id, $pin2->deals()->sole()->client_id);
    }

    public function test_open_deal_conflict_with_hidden_archived_client_shows_notice_only(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $pin = $this->pinFor($nino);
        $old = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Гиви', 'last_name' => 'Лордкипанидзе']);
        $old->delete();
        $this->actingAs($nino);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('openDeal', ['phone' => '555123456', 'first_name' => 'Нино', 'last_name' => 'Беридзе'])
            ->assertActionNotMounted('resolveDealConflict')
            ->assertNotified(__('gshome.crm.clients.archived_exists'))
            ->assertDontSee('Гиви');
        $this->assertSame('+995555123456', Client::withTrashed()->findOrFail($old->id)->phone_e164);
        $this->assertSame(0, $pin->deals()->count());
    }

    public function test_open_deal_with_archived_client_is_refused_for_agent(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $pin = $this->pinFor($nino);
        Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Гиви'])->delete();
        $this->actingAs($nino);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('openDeal', ['phone' => '555123456', 'first_name' => 'Гиви', 'last_name' => ''])
            ->assertNotified(__('gshome.crm.clients.archived_exists'));

        $this->assertSame(0, $pin->deals()->count());
    }
}
