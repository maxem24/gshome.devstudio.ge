<?php

namespace Tests\Feature\Crm;

use App\Crm\Deals;
use App\Crm\Pins;
use App\Enums\Direction;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\User;
use App\RealEstate\Listing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class DealColumnsTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;

    private User $nino;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::factory()->create();
        $this->lead = User::factory()->teamLead($company, Direction::Sale)->create();
        $this->nino = User::factory()->agent($this->lead)->create();

        $listing = Listing::fromApi(RealEstateFake::listing([
            'id' => 41,
            'deal_type' => ['id' => 1, 'slug' => 'sale', 'title' => 'sale'],
        ]), 'ru');
        $pin = app(Pins::class)->take($listing, $this->nino);
        $this->deal = app(Deals::class)->open($pin, Client::factory()->create(), $this->nino);
    }

    /** @param array<string, mixed> $values */
    private function assertRejected(array $values): void
    {
        $this->expectException(QueryException::class);
        DB::table('deals')->where('id', $this->deal->id)->update($values);
    }

    public function test_partner_cannot_be_deal_agent(): void
    {
        $this->assertRejected(['partner_user_id' => $this->nino->id]);
    }

    public function test_share_requires_amount(): void
    {
        $this->assertRejected(['partner_share' => 100, 'amount' => null]);
    }

    public function test_share_must_be_below_amount(): void
    {
        $this->assertRejected(['partner_share' => 100, 'amount' => 100]);
    }

    public function test_share_must_be_positive(): void
    {
        $this->assertRejected(['partner_share' => 0, 'amount' => 100]);
    }

    public function test_price_without_currency_rejected(): void
    {
        $this->assertRejected(['price' => 1000, 'price_currency' => null]);
    }

    public function test_currency_without_price_rejected(): void
    {
        $this->assertRejected(['price' => null, 'price_currency' => 'USD']);
    }

    public function test_valid_values_accepted(): void
    {
        DB::table('deals')->where('id', $this->deal->id)->update([
            'partner_user_id' => $this->lead->id, 'partner_share' => 50, 'amount' => 100,
            'price' => 1000, 'price_currency' => 'USD',
        ]);

        $this->assertSame($this->lead->id, $this->deal->fresh()->partner_user_id);
    }

    public function test_defaults(): void
    {
        $deal = $this->deal->fresh();

        $this->assertSame([], $deal->intake);
        $this->assertNull($deal->price);
        $this->assertNull($deal->price_currency);
        $this->assertNull($deal->partner_user_id);
        $this->assertNull($deal->partner_share);
    }
}
