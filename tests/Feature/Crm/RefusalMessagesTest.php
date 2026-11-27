<?php

namespace Tests\Feature\Crm;

use App\Crm\Deals;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Exceptions\ObjectClosed;
use App\Crm\Exceptions\RecipientOnObject;
use App\Crm\Pins;
use App\Enums\Direction;
use App\Enums\UnavailableReason;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

/** Понятные отказы: кто закрыл объект и почему, передача тому, кто уже ведёт объект. */
class RefusalMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $nino;

    private User $giorgi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
        $this->nino = User::factory()->agent($this->lead)->create();
        $this->giorgi = User::factory()->agent($this->lead)->create();
    }

    private function listing(): Listing
    {
        return Listing::fromApi(RealEstateFake::listing([
            'id' => 41, 'cluster' => null, 'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');
    }

    /** Giorgi держит объект и сделку; другой агент закрывает объект. Возвращает отказ на попытку Giorgi двигать сделку. */
    private function refusalAfterClose(User $closer, User $holder, UnavailableReason $reason): ObjectClosed
    {
        $pins = app(Pins::class);
        $holderPin = $pins->take($this->listing(), $holder);
        $closerPin = $pins->take($this->listing(), $closer);
        $deal = app(Deals::class)->open($holderPin, Client::factory()->create(), $holder);
        $pins->markUnavailable($closerPin, $reason, $closer);

        try {
            app(Deals::class)->moveStage($deal, 'viewing_scheduled', $holder);
            $this->fail('Deal on a closed object must be rejected.');
        } catch (ObjectClosed $e) {
            return $e;
        }
    }

    public function test_deal_action_on_closed_object_names_the_closer(): void
    {
        $this->app->setLocale('en');
        $e = $this->refusalAfterClose($this->nino, $this->giorgi, UnavailableReason::RentedElsewhere);

        $message = app(Pins::class)->refusal($e, $this->giorgi, 'gshome.crm.deals.forbidden');

        $this->assertStringContainsString($this->nino->name, $message);
        $this->assertStringContainsString('rented elsewhere', $message);
    }

    public function test_won_object_says_who_rented_it_out(): void
    {
        $this->app->setLocale('ru');
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(), $this->nino);
        $giorgiPin = $pins->take($this->listing(), $this->giorgi);
        $deals = app(Deals::class);
        $mine = $deals->open($ninoPin, Client::factory()->create(), $this->nino);
        $theirs = $deals->open($giorgiPin, Client::factory()->create(), $this->giorgi);
        $deals->win($mine, '1000', 'USD', $this->nino);

        try {
            $deals->win($theirs, '900', 'USD', $this->giorgi);
            $this->fail('Second win must be rejected.');
        } catch (CrmForbidden $e) {
            $this->assertSame('Объект уже сдан агентом '.$this->nino->name.'.', $pins->refusal($e, $this->giorgi, 'gshome.crm.deals.forbidden'));
        }
    }

    public function test_closer_from_other_company_stays_anonymous(): void
    {
        $this->app->setLocale('en');
        $stranger = User::factory()->agent(User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create())->create();

        $e = $this->refusalAfterClose($this->nino, $stranger, UnavailableReason::Fake);
        $message = app(Pins::class)->refusal($e, $stranger, 'gshome.crm.deals.forbidden');

        $this->assertStringNotContainsString($this->nino->name, $message);
        $this->assertStringContainsString('fake', $message);
    }

    public function test_transfer_to_agent_already_on_object_has_its_own_message(): void
    {
        $this->app->setLocale('en');
        $pins = app(Pins::class);
        $ninoPin = $pins->take($this->listing(), $this->nino);
        $pins->take($this->listing(), $this->giorgi);

        try {
            $pins->transfer($ninoPin, $this->giorgi, $this->lead);
            $this->fail('Transfer to a holder of the same object must be rejected.');
        } catch (RecipientOnObject $e) {
            $this->assertSame(__('gshome.crm.pins.transfer_already_on_object'), $pins->refusal($e, $this->lead, 'gshome.crm.pins.forbidden'));
            $this->assertNotSame(__('gshome.crm.pins.forbidden'), $pins->refusal($e, $this->lead, 'gshome.crm.pins.forbidden'));
        }
    }

    public function test_other_refusals_use_the_fallback_key(): void
    {
        $this->app->setLocale('en');

        $this->assertSame(__('gshome.crm.deals.forbidden'), app(Pins::class)->refusal(new CrmForbidden('x'), $this->nino, 'gshome.crm.deals.forbidden'));
    }

    public function test_new_keys_exist_in_every_locale(): void
    {
        $keys = ['transfer_already_on_object', 'object_closed_won', 'object_closed_won_anon', 'object_closed_rented_elsewhere',
            'object_closed_rented_elsewhere_anon', 'object_closed_fake', 'object_closed_fake_anon', 'object_closed_abandoned'];
        foreach (['ka', 'ru', 'en'] as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame('gshome.crm.pins.'.$key, __('gshome.crm.pins.'.$key, [], $locale), "{$locale}: {$key}");
            }
        }
    }
}
