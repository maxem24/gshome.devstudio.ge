<?php

namespace Tests\Feature\Org;

use App\Models\Company;
use App\Models\CompanyExchange;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CompanyExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pair_is_stored_ordered(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->sortBy('id')->values()->all();

        $pair = CompanyExchange::create(['company_a_id' => $b->id, 'company_b_id' => $a->id, 'enabled' => true]);

        $this->assertSame($a->id, $pair->refresh()->company_a_id);
        $this->assertSame($b->id, $pair->company_b_id);
    }

    public function test_reversed_duplicate_is_rejected(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);

        $this->expectException(QueryException::class);
        CompanyExchange::create(['company_a_id' => $b->id, 'company_b_id' => $a->id, 'enabled' => false]);
    }

    public function test_company_cannot_pair_with_itself(): void
    {
        $a = Company::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $a->id, 'enabled' => true]);
    }

    public function test_enabled_between_works_in_both_orders_and_respects_flag(): void
    {
        [$a, $b, $c] = Company::factory()->count(3)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $c->id, 'enabled' => false]);

        $this->assertTrue(CompanyExchange::enabledBetween($a->id, $b->id));
        $this->assertTrue(CompanyExchange::enabledBetween($b->id, $a->id));
        $this->assertFalse(CompanyExchange::enabledBetween($a->id, $c->id));
        $this->assertFalse(CompanyExchange::enabledBetween($b->id, $c->id));
        $this->assertTrue(CompanyExchange::existsBetween($c->id, $a->id));
    }
}
