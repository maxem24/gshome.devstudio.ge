<?php

namespace Tests\Feature\RealEstate;

use App\Enums\OwnershipLabel;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use App\RealEstate\Listing;
use App\RealEstate\ListingRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingRowsTest extends TestCase
{
    use RefreshDatabase;

    private function listing(array $o): Listing
    {
        return Listing::fromApi(RealEstateFake::listing($o), 'ru');
    }

    public function test_own_label_and_group_label(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $viewer = User::factory()->teamLead($a)->create();
        $ourAgent = User::factory()->agent($viewer)->create(['name' => 'Нино']);
        $our = Phone::factory()->heldBy($ourAgent)->create()->number;

        $rows = ListingRows::build($viewer, [
            $this->listing(['id' => 1, 'contact' => ['phone_e164' => $our]]),
            $this->listing(['id' => 2, 'contact' => ['phone_e164' => '+995599000001'], 'cluster' => ['id' => 9, 'listings_count' => 2, 'phones_e164' => ['+995599000001', $our]]]),
            $this->listing(['id' => 3, 'contact' => ['phone' => '555402***', 'phone_e164' => null]]),
        ]);

        $this->assertSame([1, 2, 3], array_keys($rows));
        $this->assertSame(OwnershipLabel::OurEmployee->getLabel(), $rows[1]['ownership_label']);
        $this->assertSame('Нино', $rows[1]['ownership_name']);
        $this->assertNull($rows[1]['group_label']);
        $this->assertSame(OwnershipLabel::OtherCompany->getLabel(), $rows[2]['ownership_label']);
        $this->assertSame('В группе есть наш сотрудник: Нино', $rows[2]['group_label']);
        $this->assertSame(OwnershipLabel::OtherCompany->getLabel(), $rows[3]['ownership_label']);
        $this->assertNull($rows[3]['group_label']);
    }

    public function test_group_label_picks_most_specific_and_ignores_own_number(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $viewer = User::factory()->teamLead($a)->create();
        $linked = Phone::factory()->heldBy(User::factory()->teamLead($b)->create(['name' => 'Связанный']))->create()->number;
        $ours = Phone::factory()->heldBy(User::factory()->agent($viewer)->create(['name' => 'Свой']))->create()->number;

        $rows = ListingRows::build($viewer, [
            $this->listing(['id' => 1, 'contact' => ['phone_e164' => '+995599000002'], 'cluster' => ['id' => 1, 'listings_count' => 3, 'phones_e164' => ['+995599000002', $linked, $ours]]]),
        ]);

        $this->assertSame('В группе есть наш сотрудник: Свой', $rows[1]['group_label']);
    }

    public function test_group_of_other_companies_only_has_no_group_label(): void
    {
        $viewer = User::factory()->teamLead()->create();
        $foreign = Phone::factory()->heldBy(User::factory()->teamLead()->create())->create()->number; // другая наша компания без обмена

        $rows = ListingRows::build($viewer, [
            $this->listing(['id' => 1, 'contact' => ['phone_e164' => '+995599000003'], 'cluster' => ['id' => 1, 'listings_count' => 2, 'phones_e164' => ['+995599000003', $foreign]]]),
        ]);

        $this->assertNull($rows[1]['group_label']);
    }
}
