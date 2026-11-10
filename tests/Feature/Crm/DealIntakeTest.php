<?php

namespace Tests\Feature\Crm;

use App\Crm\DealIntake;
use App\Crm\Deals;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Enums\Direction;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class DealIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $nino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team(Direction::Rent);
    }

    /** Команда нужного направления: агент берёт пин только в своём направлении. */
    private function team(Direction $direction): void
    {
        $this->lead = User::factory()->teamLead(Company::factory()->create(), $direction)->create();
        $this->nino = User::factory()->agent($this->lead)->create();
    }

    private function deal(bool $rent = true, int $id = 51): Deal
    {
        $this->team($rent ? Direction::Rent : Direction::Sale);
        $listing = Listing::fromApi(RealEstateFake::listing([
            'id' => $id,
            'deal_type' => $rent
                ? ['id' => 2, 'slug' => 'rent', 'title' => 'rent']
                : ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');
        $pin = app(Pins::class)->take($listing, $this->nino);

        return app(Deals::class)->open($pin, Client::factory()->create(), $this->nino);
    }

    public function test_saves_rent_answers_and_price(): void
    {
        $deal = $this->deal();
        $intake = ['occupants' => 'семья с ребёнком', 'term' => '1y', 'prepay' => 'first_last', 'pets' => true, 'deposit_ok' => 'yes'];

        app(Deals::class)->updateIntake($deal, $intake, '1200', 'USD', $this->nino);

        $fresh = $deal->fresh();
        $this->assertEquals($intake, $fresh->intake);
        $this->assertSame('1200.00', $fresh->price);
        $this->assertSame('USD', $fresh->price_currency);
    }

    public function test_rejects_sale_key_on_rent_deal(): void
    {
        $deal = $this->deal();

        try {
            app(Deals::class)->updateIntake($deal, ['purpose' => 'living'], null, null, $this->nino);
            $this->fail('Expected CrmForbidden');
        } catch (CrmForbidden) {
            $this->assertEmpty($deal->fresh()->intake);
        }
    }

    public function test_rejects_value_outside_list(): void
    {
        $deal = $this->deal();

        $this->expectException(CrmForbidden::class);
        try {
            app(Deals::class)->updateIntake($deal, ['term' => '2y'], null, null, $this->nino);
        } finally {
            $this->assertEmpty($deal->fresh()->intake);
        }
    }

    public function test_cash_to_mortgage_drops_bank_help(): void
    {
        $deal = $this->deal(false);
        $deals = app(Deals::class);

        $deals->updateIntake($deal, ['payment' => 'cash', 'bank_help' => true], null, null, $this->nino);
        $this->assertTrue($deal->fresh()->intake['bank_help']);

        $deals->updateIntake($deal, ['payment' => 'mortgage', 'bank_help' => true, 'down_payment' => '20000', 'down_payment_currency' => 'USD'], null, null, $this->nino);
        $intake = $deal->fresh()->intake;
        $this->assertArrayNotHasKey('bank_help', $intake);
        $this->assertSame('20000', $intake['down_payment']);
    }

    public function test_price_without_currency_rejected(): void
    {
        $deal = $this->deal();

        $this->expectException(CrmForbidden::class);
        app(Deals::class)->updateIntake($deal, [], '1200', null, $this->nino);
    }

    public function test_bad_price_and_currency_rejected(): void
    {
        $deal = $this->deal();
        $deals = app(Deals::class);

        foreach ([['-5', 'USD'], ['abc', 'USD'], ['100', 'EUR'], [null, 'USD']] as [$price, $cur]) {
            try {
                $deals->updateIntake($deal, [], $price, $cur, $this->nino);
                $this->fail("Expected CrmForbidden for {$price} {$cur}");
            } catch (CrmForbidden) {
                $this->assertNull($deal->fresh()->price);
            }
        }
    }

    public function test_closed_deal_rejected(): void
    {
        $deal = $this->deal();
        app(Deals::class)->lose($deal, 'no', $this->nino);

        $this->expectException(CrmForbidden::class);
        app(Deals::class)->updateIntake($deal, ['term' => '1y'], null, null, $this->nino);
    }

    public function test_foreign_agent_rejected(): void
    {
        $deal = $this->deal();
        $otherLead = User::factory()->teamLead(Company::factory()->create(), Direction::Sale)->create();
        $stranger = User::factory()->agent($otherLead)->create();

        $this->expectException(CrmForbidden::class);
        app(Deals::class)->updateIntake($deal, ['term' => '1y'], null, null, $stranger);
    }

    public function test_journal_lists_changes_and_noop_writes_nothing(): void
    {
        $deal = $this->deal();
        $deals = app(Deals::class);

        $deals->updateIntake($deal, ['term' => '1y'], '500', 'GEL', $this->nino);
        $this->assertSame(1, Activity::query()->where('event', Journal::DEAL_INTAKE_CHANGED)->count());
        $activity = Activity::query()->where('event', Journal::DEAL_INTAKE_CHANGED)->firstOrFail();
        $this->assertSame('1y', $activity->properties['changed']['term']['to']);
        $this->assertSame('GEL', $activity->properties['changed']['price_currency']['to']);

        $deals->updateIntake($deal, ['term' => '1y'], '500', 'GEL', $this->nino);
        $this->assertSame(1, Activity::query()->where('event', Journal::DEAL_INTAKE_CHANGED)->count());
    }

    public function test_old_keys_read_without_error(): void
    {
        $deal = $this->deal();
        DB::table('deals')->where('id', $deal->id)->update(['intake' => json_encode(['purpose' => 'living'])]);

        $this->assertNotSame('', DealIntake::label('purpose', 'living'));
        $this->assertNotSame('', DealIntake::label('pets', true));

        app(Deals::class)->updateIntake($deal->fresh(), ['term' => '6m'], null, null, $this->nino);
        $this->assertEquals(['term' => '6m'], $deal->fresh()->intake);
    }

    /** Подписи плоские: вложенный массив в переводе сломал бы label(): string. */
    public function test_every_intake_label_resolves_to_a_string_in_every_locale(): void
    {
        foreach (['ru', 'en', 'ka'] as $locale) {
            app()->setLocale($locale);
            $this->assertIsString(__('gshome.crm.intake.title'));
            $this->assertNotSame('gshome.crm.intake.title', __('gshome.crm.intake.title'), $locale);
            foreach (['yes', 'no'] as $key) {
                $this->assertNotSame('gshome.crm.intake.'.$key, __('gshome.crm.intake.'.$key), "{$locale} {$key}");
            }
            foreach ([Direction::Rent, Direction::Sale] as $direction) {
                foreach (DealIntake::fields($direction) as $key) {
                    $this->assertNotSame('gshome.crm.intake.'.$key, DealIntake::label($key), "{$locale} {$key}");
                    if ($key === 'down_payment_currency') {
                        continue; // подписью служит сам код валюты
                    }
                    foreach (DealIntake::options($key) ?? [] as $option) {
                        $label = DealIntake::label($key, $option);
                        $this->assertStringStartsNotWith('gshome.crm.intake.', $label, "{$locale} {$key}={$option}");
                    }
                }
            }
        }
    }
}
