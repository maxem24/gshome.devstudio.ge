<?php

namespace Tests\Feature\Filament;

use App\Crm\Deals;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Enums\ClientHousing;
use App\Enums\DealStatus;
use App\Enums\Direction;
use App\Enums\UnavailableReason;
use App\Filament\Pages\ListingView;
use App\Jobs\PollListingRefresh;
use App\Models\Client;
use App\Models\Company;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\ListingSearch;
use App\Models\Phone;
use App\Models\SavedListing;
use App\Models\User;
use App\RealEstate\Listing;
use App\RealEstate\RealEstateClient;
use App\Search\LinkImports;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
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
        // Очередь в тестах синхронная: опрос актуализации (PollListingRefresh) крутился бы внутри клика.
        Queue::fake();
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

    /** @return array{0: User, 1: ListingSearch} продавец и его поиск по сайтам, нашедший аренду (13) */
    private function sellerWithRentSearch(array $ids = [13]): array
    {
        $agent = User::factory()->agent(User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create())->create();
        $search = ListingSearch::query()->create([
            'user_id' => $agent->id, 'filters' => ['deal' => 'rent'], 'body_hash' => sha1((string) microtime()),
            'remote_id' => (string) Str::uuid(), 'status' => 'done', 'sites' => [], 'found' => count($ids),
            'listing_ids' => $ids, 'started_at' => now(), 'finished_at' => now(),
        ]);
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing(['deal_type' => ['id' => 2, 'slug' => 'rent']])]);

        return [$agent, $search];
    }

    public function test_other_direction_opens_from_own_search_containing_it(): void
    {
        [$agent, $search] = $this->sellerWithRentSearch();

        $this->actingAs($agent)->get('/admin/listings/13?search='.$search->id)->assertOk();
        $this->assertSame(1, Activity::query()->where('event', Journal::LISTING_VIEWED)->count());
    }

    public function test_other_direction_without_search_is_404(): void
    {
        [$agent] = $this->sellerWithRentSearch();

        $this->actingAs($agent)->get('/admin/listings/13')->assertNotFound();
        $this->actingAs($agent)->get('/admin/listings/13?search=abc')->assertNotFound();
    }

    public function test_other_direction_with_foreign_search_is_404(): void
    {
        [$agent] = $this->sellerWithRentSearch();
        [, $foreign] = $this->sellerWithRentSearch();

        $this->actingAs($agent)->get('/admin/listings/13?search='.$foreign->id)->assertNotFound();
    }

    public function test_other_direction_with_search_not_containing_listing_is_404(): void
    {
        [$agent, $search] = $this->sellerWithRentSearch([14]);

        $this->actingAs($agent)->get('/admin/listings/13?search='.$search->id)->assertNotFound();
    }

    public function test_search_does_not_open_a_direction_the_search_did_not_use(): void
    {
        [$agent, $search] = $this->sellerWithRentSearch();
        $search->update(['filters' => ['deal' => 'sale']]);

        $this->actingAs($agent)->get('/admin/listings/13?search='.$search->id)->assertNotFound();
    }

    public function test_other_direction_opens_by_own_link_import(): void
    {
        [$agent] = $this->sellerWithRentSearch();

        $this->actingAs($agent)->get(LinkImports::cardUrl($agent, 13))->assertOk();
    }

    public function test_link_signature_of_another_person_or_listing_is_404(): void
    {
        [$agent] = $this->sellerWithRentSearch();
        [$other] = $this->sellerWithRentSearch();

        $this->actingAs($agent)->get(LinkImports::cardUrl($other, 13))->assertNotFound();
        $this->actingAs($agent)->get('/admin/listings/13?link='.substr(LinkImports::cardUrl($agent, 14), -64))->assertNotFound();
        $this->actingAs($agent)->get('/admin/listings/13?link=x')->assertNotFound();
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

    public function test_detail_shows_filled_facts_and_hides_empty_extras(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing([
            'bedrooms' => 2,
            'description' => ['ru' => 'Цена 51000$<br />Тел: 510102767'],
            'images' => [
                ['url' => 'https://re.test/storage/1.webp', 'url_thumb' => 'https://re.test/storage/1_t.webp', 'is_main' => true, 'order' => 1],
                ['url' => 'https://re.test/storage/2.webp', 'url_thumb' => 'https://re.test/storage/2_t.webp', 'is_main' => false, 'order' => 2],
            ],
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
            ->assertDontSee('Санузлы')
            ->assertSee('Лифт')
            ->assertDontSee('Газ')
            ->assertSee('Цена 51000$')
            ->assertSee('Тел: 510102767')
            ->assertDontSee('<br')
            ->assertDontSee('&lt;br')
            ->assertSeeHtml('data-listing-carousel')
            ->assertSeeHtml('tel:555123456')
            ->assertSee('Позвонить');
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

    public function test_soft_match_is_marked_on_the_copy_and_on_the_listing(): void
    {
        $soft = ['type' => 'soft', 'confidence' => 70, 'missing' => ['rooms'], 'evidence' => ['phone'], 'explanation' => 'У одной копии не указаны комнаты, совпал телефон.'];
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing([
            'match' => $soft,
            'cluster' => ['id' => 5, 'match' => 'soft', 'min_confidence' => 70, 'listings_count' => 2, 'phones_e164' => [],
                'listings' => [
                    ['id' => 55, 'source' => 'ss_ge', 'is_primary' => true, 'seller' => [], 'contact' => [], 'match' => ['type' => 'full', 'confidence' => 100]],
                    ['id' => 56, 'source' => 'myhome_ge', 'is_primary' => false, 'seller' => [], 'contact' => [], 'match' => $soft],
                ]],
        ])]);
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee('Это объявление попало в группу по мягкой склейке, уверенность 70 %.')
            ->assertSee('У одной копии не указаны комнаты, совпал телефон.')
            ->assertSeeHtml('Возможно, та же квартира · 70 %')
            ->assertDontSee('· 100 %');
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

    public function test_holder_saves_landlord_preferences_and_sees_them(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create(['name' => 'Нино']);
        $pin = $this->pinFor($nino);
        $this->actingAs($nino);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertActionVisible('landlordPreferences')
            ->mountAction('landlordPreferences')
            ->assertFormFieldExists('occupants')
            ->assertFormFieldDoesNotExist('price_min')
            ->assertFormFieldDoesNotExist('subdistrict_ids')
            ->assertFormFieldDoesNotExist('rooms_min')
            ->callMountedAction()
            ->callAction('landlordPreferences', data: [
                'occupants' => ['couple', 'small_family'],
                'home' => ['furnished'],
                'people' => 3,
                'note' => '  Без животных  ',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified(__('gshome.crm.pins.preferences_saved'))
            ->assertSee(__('gshome.crm.pins.preferences_title'))
            ->assertSee('Пара, Маленькая семья')
            ->assertSee('Без животных');

        // jsonb хранит ключи в своём порядке: сравниваем без учёта порядка ключей.
        $expected = ['occupants' => ['couple', 'small_family'], 'people' => 3, 'home' => ['furnished'], 'note' => 'Без животных'];
        $saved = (array) $pin->fresh()?->preferences;
        ksort($expected);
        ksort($saved);
        $this->assertSame($expected, $saved);
    }

    /** Объект аренды в работе у агента направления «аренда». */
    private function rentPin(User $by): ListingPin
    {
        $data = RealEstateFake::listing(['deal_type' => ['id' => 2, 'slug' => 'rent', 'title' => 'Сдаётся'], 'subdistrict' => ['id' => 101, 'title' => 'Ваке'], 'price' => ['usd' => 600.0]]);
        RealEstateFake::fake(cards: [13 => $data]);

        return app(Pins::class)->take(Listing::fromApi($data, 'ru'), $by);
    }

    private function rentAgent(): User
    {
        return User::factory()->agent(User::factory()->teamLead(null, Direction::Rent)->create())->create();
    }

    public function test_holder_sees_probable_clients(): void
    {
        $agent = $this->rentAgent();
        $this->rentPin($agent);
        Client::factory()->create(['first_name' => 'Лаша', 'housing' => ClientHousing::Looking, 'profile' => ['subdistrict_ids' => [101], 'price_max' => 650]]);
        $this->actingAs($agent);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee(__('gshome.crm.matching.clients_title'))
            ->assertSee('Лаша')
            ->assertSee('100%');
    }

    public function test_probable_clients_hidden_for_others_and_sale(): void
    {
        $this->rentPin($this->rentAgent());
        Client::factory()->create(['housing' => ClientHousing::Looking, 'profile' => ['subdistrict_ids' => [101]]]);
        $this->actingAs($this->rentAgent());
        Livewire::test(ListingView::class, ['listing' => 13])->assertDontSee(__('gshome.crm.matching.clients_title'));

        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $this->pinFor($nino, 14);
        RealEstateFake::fake(cards: [14 => RealEstateFake::listing(['id' => 14])]);
        $this->actingAs($nino);
        Livewire::test(ListingView::class, ['listing' => 14])->assertDontSee(__('gshome.crm.matching.clients_title'));
    }

    /** Опрос актуализации идёт раз в 2 секунды: подбор не должен считаться заново на каждом. */
    public function test_matches_are_not_recomputed_on_every_render(): void
    {
        $agent = $this->rentAgent();
        $this->rentPin($agent);
        Client::factory()->create(['housing' => ClientHousing::Looking, 'profile' => ['subdistrict_ids' => [101]]]);
        $this->actingAs($agent);
        $runs = 0;
        DB::listen(function ($q) use (&$runs): void {
            if (str_contains($q->sql, 'CROSS JOIN listing_pins')) {
                $runs++;
            }
        });

        Livewire::test(ListingView::class, ['listing' => 13])->call('$refresh')->call('$refresh');

        $this->assertSame(1, $runs);
    }

    public function test_probable_clients_empty_state(): void
    {
        $agent = $this->rentAgent();
        $this->rentPin($agent);
        $this->actingAs($agent);

        Livewire::test(ListingView::class, ['listing' => 13])->assertSee(__('gshome.crm.matching.empty'));
    }

    public function test_other_agent_cannot_edit_landlord_preferences(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $pin = $this->pinFor(User::factory()->agent(User::factory()->teamLead()->create())->create());
        $pin->update(['preferences' => ['note' => 'Секрет собственника']]);
        $this->actingAs(User::factory()->agent(User::factory()->teamLead()->create())->create());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertActionHidden('landlordPreferences')
            ->assertDontSee(__('gshome.crm.pins.preferences_title'))
            ->assertDontSee('Секрет собственника');
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
            ->assertDontSee(__('gshome.crm.pins.holders_title'))
            ->assertDontSee('Нино')
            ->assertActionVisible('takeInWork')
            ->assertActionHidden('transferPin')
            ->assertActionHidden('openDeal')
            ->assertActionHidden('releasePin');

        // Владелец группы видит имя.
        $this->actingAs(User::factory()->owner()->create());
        Livewire::test(ListingView::class, ['listing' => 13])->assertSee(__('gshome.crm.pins.holders_title'))->assertSee('Нино');
    }

    public function test_colleague_of_same_company_sees_holder_name(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $this->pinFor($nino);

        $this->actingAs(User::factory()->agent($lead)->create());
        Livewire::test(ListingView::class, ['listing' => 13])->assertSee(__('gshome.crm.pins.holders_title'))->assertSee('Нино');
    }

    public function test_second_agent_sees_take_button_and_holder_list(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $giorgi = User::factory()->agent($lead)->create(['name' => 'Гиорги']);
        $this->actingAs($giorgi);

        $page = Livewire::test(ListingView::class, ['listing' => 13]);
        $this->pinFor($nino); // Нино взяла между открытием и кликом
        $page->call('$refresh')
            ->assertSee(__('gshome.crm.pins.holders_title'))->assertSee('Нино')
            ->assertActionVisible('takeInWork')
            ->callAction('takeInWork')
            ->assertNotified(__('gshome.crm.pins.taken'))
            ->assertActionHidden('takeInWork')
            ->assertSee('Гиорги')
            ->assertActionVisible('releasePin');

        $this->assertSame(2, ListingPin::query()->count());
        $this->assertSame(1, ListingObject::query()->count());
    }

    public function test_unavailable_requires_reason_and_fake_closes_for_all(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create();
        $giorgi = User::factory()->agent($lead)->create();
        $mine = $this->pinFor($nino);
        $other = $this->pinFor($giorgi);
        $this->actingAs($nino);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('markUnavailable', [])->assertHasActionErrors(['reason' => 'required']);
        $this->assertTrue($mine->refresh()->isActive());

        // «Другая причина» закрывает только свою работу.
        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('markUnavailable', ['reason' => 'other'])->assertHasNoActionErrors();
        $this->assertFalse($mine->refresh()->isActive());
        $this->assertTrue($other->refresh()->isActive());

        // «Фейк» закрывает объект для всех.
        $this->actingAs($giorgi);
        Livewire::test(ListingView::class, ['listing' => 13])->callAction('markUnavailable', ['reason' => 'fake']);
        $this->assertFalse($other->refresh()->isActive());
        $this->assertSame('fake', ListingObject::query()->sole()->closed_reason);
    }

    public function test_transfer_recipients_exclude_agents_already_on_object(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $giorgi = User::factory()->agent($lead)->create(['name' => 'Гиорги']);
        $free = User::factory()->agent($lead)->create(['name' => 'Свободный']);
        $pin = $this->pinFor($nino);
        $this->pinFor($giorgi);
        $this->actingAs($lead);

        // Гиорги уже ведёт объект: в списке получателей его нет, передача ему отклоняется.
        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('transferPin', ['to' => $giorgi->id])
            ->assertHasActionErrors(['to']);
        $this->assertSame($nino->id, $pin->refresh()->user_id);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('transferPin', ['to' => $free->id])
            ->assertNotified(__('gshome.crm.pins.transferred'));
        $this->assertSame($free->id, $pin->refresh()->user_id);
    }

    public function test_removed_listing_still_finds_my_pin(): void
    {
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $this->pinFor($nino);
        $this->actingAs($nino);
        $page = Livewire::test(ListingView::class, ['listing' => 13]);

        // Теперь API отдаёт 404: кнопки по своему пину остаются.
        RealEstateFake::fake();
        app(RealEstateClient::class)->forgetCard(13);
        $page->call('$refresh')
            ->assertActionVisible('releasePin')
            ->assertActionVisible('markUnavailable')
            ->assertActionHidden('takeInWork');
    }

    public function test_closed_object_banner_shows_who_rented(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $giorgi = User::factory()->agent($lead)->create(['name' => 'Гиорги']);
        $this->pinFor($nino);
        $pin = $this->pinFor($giorgi);
        app(Pins::class)->markUnavailable($pin, UnavailableReason::RentedElsewhere, $giorgi);
        $this->actingAs($lead);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->assertSee(UnavailableReason::RentedElsewhere->getLabel())
            ->assertSee('Гиорги')
            ->assertActionVisible('takeInWork');
    }

    public function test_warning_when_client_in_other_agents_deal(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $giorgi = User::factory()->agent($lead)->create();
        $client = Client::factory()->create(['housing' => ClientHousing::Looking]);
        app(Deals::class)->open($this->pinFor($nino), $client, $nino);
        $pin = $this->pinFor($giorgi);
        $this->actingAs($giorgi);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('openDeal', ['client_id' => $client->id])
            ->assertNotified(__('gshome.crm.pins.client_in_other_deal', ['names' => 'Нино']));
        $this->assertSame(1, $pin->deals()->count());
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
            ->assertSee(__('gshome.crm.pins.holders_title'))->assertSee('Нино')
            ->assertActionVisible('takeInWork');
    }

    public function test_transfer_offers_only_allowed_recipients(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create(['name' => 'Нино']);
        $giorgi = User::factory()->agent($lead)->create(['name' => 'Гиорги']);
        User::factory()->agent(User::factory()->teamLead()->create())->create(['name' => 'Чужой']);
        $this->pinFor($nino);
        $this->actingAs($lead);

        $page = Livewire::test(ListingView::class, ['listing' => 13])
            ->mountAction('transferPin')
            ->assertSee('Гиорги')
            ->assertDontSee('Чужой');
        $page->setActionData(['to' => $giorgi->id])->callMountedAction()->assertNotified(__('gshome.crm.pins.transferred'));

        $this->assertSame($giorgi->id, ListingPin::query()->sole()->user_id);
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

        Livewire::test(ListingView::class, ['listing' => 13])->callAction('markUnavailable', ['reason' => 'other']);

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
        $this->assertSame(ClientHousing::Looking, $client->housing);
    }

    public function test_open_deal_picks_a_client_who_is_looking_and_refuses_one_who_rents(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $pin = $this->pinFor($nino);
        $looking = Client::factory()->create(['housing' => ClientHousing::Looking, 'first_name' => 'Нино', 'phone_e164' => '+995555111111']);
        $renting = Client::factory()->create(['housing' => ClientHousing::Renting, 'first_name' => 'Гиви', 'phone_e164' => '+995555222222']);
        $this->actingAs($nino);

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('openDeal', ['client_id' => $renting->id])
            ->assertHasFormErrors(['client_id']);
        $this->assertSame(0, $pin->deals()->count());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('openDeal', ['client_id' => $looking->id])
            ->assertNotified(__('gshome.crm.pins.deal_opened'));
        $this->assertSame($looking->id, $pin->deals()->sole()->client_id);
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

        // Выбор «тот же человек» записан в журнал: субъект — клиент, в свойствах только id.
        $entry = Activity::query()->where('event', Journal::CLIENT_SAME_PERSON_CONFIRMED)->sole();
        $this->assertSame($old->id, (int) $entry->subject_id);
        $this->assertSame($nino->id, (int) $entry->causer_id);
        $this->assertSame(['client_id' => $old->id], $entry->properties->toArray());
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

    public function test_conflict_confirm_after_pin_lost_changes_nothing(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $lead = User::factory()->teamLead()->create();
        $nino = User::factory()->agent($lead)->create();
        $giorgi = User::factory()->agent($lead)->create();
        $pin = $this->pinFor($nino);
        $old = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Гиви', 'last_name' => 'Лордкипанидзе']);
        $this->actingAs($nino);

        $page = Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('openDeal', ['phone' => '555123456', 'first_name' => 'Нино', 'last_name' => 'Беридзе'])
            ->assertActionMounted('resolveDealConflict');
        // Пока окно открыто, объект передали другому.
        app(Pins::class)->transfer($pin, $giorgi, $nino);
        $page->callMountedAction();

        $this->assertSame('+995555123456', $old->refresh()->phone_e164);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame(0, $pin->deals()->count());
    }

    public function test_forged_actions_by_non_manager_change_nothing(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing()]);
        $nino = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $giorgi = User::factory()->agent(User::factory()->teamLead()->create())->create();
        $pin = $this->pinFor($nino);
        $old = Client::factory()->create(['phone_e164' => '+995555123456', 'first_name' => 'Гиви', 'last_name' => 'Лордкипанидзе']);
        $this->actingAs($giorgi);

        $page = Livewire::test(ListingView::class, ['listing' => 13]);
        foreach (['transferPin', 'releasePin', 'markUnavailable', 'openDeal', 'resolveDealConflict'] as $name) {
            try {
                $page->call('mountAction', $name)->callMountedAction();
            } catch (\Throwable) {
            }
        }

        $pin->refresh();
        $this->assertTrue($pin->isActive());
        $this->assertSame($nino->id, $pin->user_id);
        $this->assertSame(0, $pin->deals()->count());
        $this->assertSame('+995555123456', $old->refresh()->phone_e164);
        $this->assertSame(1, Client::query()->count());
    }

    private const REFRESH_ID = '9f3c2a1e-7b4d-4c55-9a0e-1d2f3a4b5c6d';

    public function test_refresh_button_visible_when_listing_is_refreshable(): void
    {
        $this->actingAs(User::factory()->teamLead()->create());
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing(['refreshable' => true])]);

        Livewire::test(ListingView::class, ['listing' => 13])->assertActionVisible('collectFullInfo');
    }

    public function test_refresh_button_hidden_when_listing_is_not_refreshable(): void
    {
        $this->actingAs(User::factory()->teamLead()->create());
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing(['refreshable' => false])]);

        Livewire::test(ListingView::class, ['listing' => 13])->assertActionHidden('collectFullInfo');
    }

    public function test_refresh_start_sets_id_hides_button_and_journals(): void
    {
        Queue::fake();
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing(['refreshable' => true])],
            refreshPosts: [Http::response(['data' => RealEstateFake::refresh()], 202)],
        );
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('collectFullInfo')
            ->assertSet('refreshId', self::REFRESH_ID)
            ->assertActionHidden('collectFullInfo')
            ->assertSee('Собираем данные с сайтов')
            ->assertSee('проверяем…');

        $this->assertSame(13, Activity::query()->where('event', Journal::LISTING_REFRESH_STARTED)->sole()->properties['listing_id']);
        Queue::assertPushed(PollListingRefresh::class, fn (PollListingRefresh $j): bool => $j->refreshId === self::REFRESH_ID && $j->listingId === 13);
    }

    public function test_refresh_already_done_does_not_queue_poll_job(): void
    {
        Queue::fake();
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing(['refreshable' => true])],
            refreshPosts: [Http::response(['data' => RealEstateFake::refresh(['status' => 'done', 'listing' => RealEstateFake::listing()])], 200)],
        );
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(ListingView::class, ['listing' => 13])->callAction('collectFullInfo');

        Queue::assertNotPushed(PollListingRefresh::class);
    }

    public function test_refresh_done_updates_price_and_clears_id(): void
    {
        $done = RealEstateFake::refresh([
            'status' => 'done', 'eta_seconds' => 0,
            'items' => [['id' => 13, 'source' => 'ss_ge', 'source_url' => 'https://home.ss.ge/13', 'status' => 'active', 'outcome' => 'refreshed',
                'checked_at' => now()->toIso8601String(), 'deactivated_at' => null, 'error' => null, 'paused_until' => null]],
            'listing' => RealEstateFake::listing(['refreshable' => true, 'price' => ['usd' => 85000.0, 'gel' => 229500.0]]),
        ]);
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing(['refreshable' => true])],
            refreshPosts: [Http::response(['data' => RealEstateFake::refresh()], 202)],
            refreshGets: [Http::response(['data' => $done])],
        );
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('collectFullInfo')
            ->call('pollRefresh')
            ->assertSet('refreshId', null)
            ->assertSet('card.price', '$85 000')
            ->assertSet('refresh.price_before', '$90 000')
            ->assertSee('Цена изменилась')
            ->assertSee('ss.ge — обновлено')
            ->assertActionVisible('collectFullInfo');
    }

    public function test_refresh_of_removed_listing_shows_banner_and_keeps_card(): void
    {
        $done = RealEstateFake::refresh([
            'status' => 'done', 'eta_seconds' => 0,
            'items' => [['id' => 13, 'source' => 'ss_ge', 'status' => 'inactive', 'outcome' => 'removed',
                'checked_at' => '2026-10-27T10:00:00+04:00', 'deactivated_at' => '2026-10-27T10:00:00+04:00']],
            'listing' => RealEstateFake::listing(['status' => 'inactive', 'price' => ['usd' => 1.0]]),
        ]);
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing(['refreshable' => true])],
            refreshPosts: [Http::response(['data' => RealEstateFake::refresh()], 202)],
            refreshGets: [Http::response(['data' => $done])],
        );
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('collectFullInfo')
            ->call('pollRefresh')
            ->assertSet('refreshId', null)
            ->assertSet('refresh.removed', true)
            ->assertSet('card.price', '$90 000')
            ->assertSee('Объявление снято с публикации');
    }

    public function test_refresh_busy_notifies_and_leaves_no_id(): void
    {
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing(['refreshable' => true])],
            refreshPosts: [Http::response(['error' => ['code' => 'refresh_busy']], 503, ['Retry-After' => '30'])],
        );
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('collectFullInfo')
            ->assertSet('refreshId', null)
            ->assertNotified(__('gshome.listings.refresh.busy'));
        $this->assertSame(0, Activity::query()->where('event', Journal::LISTING_REFRESH_STARTED)->count());
    }

    public function test_refresh_gives_up_after_deadline(): void
    {
        $running = RealEstateFake::refresh(['status' => 'running', 'deadline_at' => now()->subMinute()->toIso8601String()]);
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing(['refreshable' => true])],
            refreshPosts: [Http::response(['data' => $running], 202)],
            refreshGets: [Http::response(['data' => $running])],
        );
        $this->actingAs(User::factory()->teamLead()->create());

        Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('collectFullInfo')
            ->call('pollRefresh')
            ->assertSet('refreshId', null)
            ->assertSee('Сайты не ответили вовремя');
    }

    public function test_refresh_id_cannot_be_set_from_the_browser(): void
    {
        RealEstateFake::fake(cards: [13 => RealEstateFake::listing(['refreshable' => true])]);
        $this->actingAs(User::factory()->teamLead()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(ListingView::class, ['listing' => 13])->set('refreshId', self::REFRESH_ID);
    }

    /** Три копии: одна пришла, одна «снята», одна не дождалась. */
    private function startedRefresh(array $get, array $cards = []): Testable
    {
        RealEstateFake::fake(
            cards: $cards ?: [13 => RealEstateFake::listing(['refreshable' => true])],
            refreshPosts: [Http::response(['data' => RealEstateFake::refresh()], 202)],
            refreshGets: [$get[0]],
        );

        return Livewire::test(ListingView::class, ['listing' => 13])->callAction('collectFullInfo');
    }

    public function test_pin_survives_removed_listing(): void
    {
        $lead = User::factory()->teamLead()->create();
        $this->actingAs($lead);
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing(['refreshable' => true])],
            refreshPosts: [Http::response(['data' => RealEstateFake::refresh()], 202)],
            refreshGets: [Http::response(['data' => RealEstateFake::refresh([
                'status' => 'done', 'eta_seconds' => 0,
                'items' => [['id' => 13, 'source' => 'ss_ge', 'status' => 'inactive', 'outcome' => 'removed',
                    'checked_at' => '2026-10-27T10:00:00+04:00', 'deactivated_at' => '2026-10-27T10:00:00+04:00']],
                'listing' => RealEstateFake::listing(['status' => 'inactive']),
            ])])],
        );
        $pin = app(Pins::class)->take(Listing::fromApi(RealEstateFake::listing(), 'ru'), $lead);

        $page = Livewire::test(ListingView::class, ['listing' => 13])->callAction('collectFullInfo')->call('pollRefresh');

        // Теперь GET /listings/13 даёт 404 (кеш сброшен), закрепление должно остаться на месте.
        RealEstateFake::fake();
        $page->call('$refresh')
            ->assertSet('refresh.removed', true)
            ->assertSee(__('gshome.crm.pins.in_work_mine'))
            ->assertActionHidden('takeInWork')
            ->assertActionVisible('markUnavailable');
        $this->assertTrue($pin->refresh()->isActive());
    }

    public function test_take_in_work_hidden_for_removed_listing(): void
    {
        $this->actingAs(User::factory()->teamLead()->create());
        $page = $this->startedRefresh([Http::response(['data' => RealEstateFake::refresh([
            'status' => 'done', 'eta_seconds' => 0,
            'items' => [['id' => 13, 'source' => 'ss_ge', 'status' => 'inactive', 'outcome' => 'removed',
                'checked_at' => '2026-10-27T10:00:00+04:00', 'deactivated_at' => '2026-10-27T10:00:00+04:00']],
            'listing' => RealEstateFake::listing(['status' => 'inactive']),
        ])])]);

        $page->call('pollRefresh')->assertActionHidden('takeInWork');
    }

    public function test_poll_stops_on_access_denied(): void
    {
        $this->assertPollStopsOn(403, __('gshome.errors.access_denied'));
    }

    public function test_poll_stops_on_bad_request(): void
    {
        $this->assertPollStopsOn(400, __('gshome.errors.bad_request'));
    }

    private function assertPollStopsOn(int $status, string $message): void
    {
        $this->actingAs(User::factory()->teamLead()->create());
        $page = $this->startedRefresh([Http::response(['error' => ['code' => 'x']], $status)]);

        $page->call('pollRefresh')
            ->assertSet('refreshId', null)
            ->assertSet('refresh.finished', true)
            ->assertSet('refresh.error', $message);
    }

    public function test_poll_waits_out_rate_limit_without_calling_api(): void
    {
        $this->actingAs(User::factory()->teamLead()->create());
        $page = $this->startedRefresh([Http::response([], 429, ['Retry-After' => '30'])]);

        $page->call('pollRefresh')->assertSet('refreshId', self::REFRESH_ID);
        $this->assertNotNull($page->get('refresh.retry_until'));

        Http::fake(fn () => throw new \LogicException('API must not be called before retry_until'));
        $page->call('pollRefresh')->assertSet('refreshId', self::REFRESH_ID);
    }

    public function test_start_rate_limit_hides_button_until_retry_after(): void
    {
        $this->actingAs(User::factory()->teamLead()->create());
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing(['refreshable' => true])],
            refreshPosts: [Http::response([], 429, ['Retry-After' => '30'])],
        );

        $page = Livewire::test(ListingView::class, ['listing' => 13])
            ->callAction('collectFullInfo')
            ->assertSet('refreshId', null)
            ->assertActionHidden('collectFullInfo');

        $this->travel(31)->seconds();
        $page->call('$refresh')->assertActionVisible('collectFullInfo');
    }

    public function test_lost_refresh_does_not_claim_done_and_marks_pending_as_not_waited(): void
    {
        $this->actingAs(User::factory()->teamLead()->create());
        $page = $this->startedRefresh([Http::response(['error' => ['code' => 'not_found']], 404)]);

        $page->call('pollRefresh')
            ->assertSet('refreshId', null)
            ->assertSet('refresh.lost', true)
            ->assertSee(__('gshome.listings.refresh.lost'))
            ->assertDontSee(__('gshome.listings.refresh.done'))
            ->assertSee(__('gshome.listings.refresh.outcomes.not_waited'))
            ->assertDontSee(__('gshome.listings.refresh.outcomes.pending'));
    }

    public function test_refill_to_unavailable_direction_keeps_card(): void
    {
        // Открыли как «продажа» (у смотрящего только она), актуализация вернула «аренду».
        $this->actingAs(User::factory()->teamLead()->create());
        $rent = RealEstateFake::listing(['refreshable' => true, 'price' => ['usd' => 10.0], 'deal_type' => ['slug' => 'rent']]);
        $page = $this->startedRefresh([Http::response(['data' => RealEstateFake::refresh(['status' => 'done', 'eta_seconds' => 0, 'listing' => $rent])])]);

        $before = $page->get('card');
        $page->call('pollRefresh')->assertSet('refreshId', null);
        $this->assertSame($before, $page->get('card'));
    }

    public function test_cache_of_card_is_forgotten_on_start_and_on_done(): void
    {
        $this->actingAs(User::factory()->teamLead()->create());
        RealEstateFake::fake(
            cards: [13 => RealEstateFake::listing(['refreshable' => true])],
            refreshPosts: [Http::response(['data' => RealEstateFake::refresh()], 202)],
            refreshGets: [Http::response(['data' => RealEstateFake::refresh(['status' => 'done', 'eta_seconds' => 0])])],
        );
        $cardGets = fn (): int => Http::recorded(fn ($r) => $r->method() === 'GET' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/listings/13'))->count();
        $client = app(RealEstateClient::class);

        $page = Livewire::test(ListingView::class, ['listing' => 13]);
        $afterMount = $cardGets();
        $client->listing(13);
        $this->assertSame($afterMount, $cardGets(), 'карточка взята из кеша');

        $page->callAction('collectFullInfo');
        $client->listing(13);
        $this->assertGreaterThan($afterMount, $cardGets(), 'после старта кеш сброшен');

        $afterStart = $cardGets();
        $client->listing(13);
        $this->assertSame($afterStart, $cardGets());
        $page->call('pollRefresh');
        $client->listing(13);
        $this->assertGreaterThan($afterStart, $cardGets(), 'после done кеш сброшен');
    }
}
