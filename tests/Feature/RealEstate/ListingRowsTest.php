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
        $this->assertNotContains('Тот же владелец', $this->notes($rows[1]));
        $this->assertContains('Компания: '.$a->name, $this->notes($rows[1]));
        $this->assertSame([], $this->notesStarting($rows[1], 'В группе есть наш сотрудник'));
        $this->assertSame(OwnershipLabel::OtherCompany->getLabel(), $rows[2]['ownership_label']);
        $this->assertContains('В группе есть наш сотрудник: Нино', $this->notes($rows[2]));
        $this->assertSame(OwnershipLabel::OtherCompany->getLabel(), $rows[3]['ownership_label']);
        $this->assertSame([], $this->notesStarting($rows[3], 'В группе есть наш сотрудник'));
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

        $this->assertContains('В группе есть наш сотрудник: Свой', $this->notes($rows[1]));
    }

    public function test_group_of_other_companies_only_has_no_group_label(): void
    {
        $viewer = User::factory()->teamLead()->create();
        $foreign = Phone::factory()->heldBy(User::factory()->teamLead()->create())->create()->number; // другая наша компания без обмена

        $rows = ListingRows::build($viewer, [
            $this->listing(['id' => 1, 'contact' => ['phone_e164' => '+995599000003'], 'cluster' => ['id' => 1, 'listings_count' => 2, 'phones_e164' => ['+995599000003', $foreign]]]),
        ]);

        $this->assertSame([], $this->notesStarting($rows[1], 'В группе есть наш сотрудник'));
    }

    public function test_linked_company_shows_the_same_owner_and_its_own_name(): void
    {
        [$a, $b] = Company::factory()->count(2)->create()->all();
        CompanyExchange::create(['company_a_id' => $a->id, 'company_b_id' => $b->id, 'enabled' => true]);
        $viewer = User::factory()->teamLead($a)->create();
        $phone = Phone::factory()->heldBy(User::factory()->teamLead($b)->create(['name' => 'Лида']))->create()->number;

        $rows = ListingRows::build($viewer, [
            $this->listing(['id' => 1, 'contact' => ['phone_e164' => $phone]]),
        ]);

        $this->assertContains('Тот же владелец', $this->notes($rows[1]));
        $this->assertContains('Компания: '.$b->name, $this->notes($rows[1]));
        $this->assertNotContains('У собственника на площадке остаётся своё объявление', $this->notes($rows[1]));
    }

    public function test_owner_copy_and_our_copy_are_named_separately(): void
    {
        $company = Company::factory()->create();
        $viewer = User::factory()->teamLead($company)->create();
        $our = Phone::factory()->heldBy(User::factory()->agent($viewer)->create())->create()->number;
        $ownerPhone = '+995599000099';

        $rows = ListingRows::build($viewer, [
            $this->listing([
                'id' => 1,
                'seller' => ['is_owner' => true, 'is_agency' => false, 'agency_name' => null],
                'contact' => ['phone_e164' => $ownerPhone],
                'cluster' => ['id' => 4, 'listings_count' => 2, 'phones_e164' => [$ownerPhone, $our]],
            ]),
            $this->listing([
                'id' => 2,
                'seller' => ['is_owner' => false, 'is_agency' => true, 'agency_name' => 'Дом'],
                'contact' => ['phone_e164' => $our],
                'cluster' => [
                    'id' => 5,
                    'listings_count' => 2,
                    'phones_e164' => [$our, $ownerPhone],
                    'listings' => [[
                        'id' => 9,
                        'source' => 'ss_ge',
                        'seller' => ['is_owner' => true, 'is_agency' => false, 'agency_name' => null],
                        'contact' => ['phone_e164' => $ownerPhone],
                        'price' => ['usd' => 1],
                    ]],
                ],
            ]),
        ]);

        $this->assertContains('Это объявление есть у агентства: '.$company->name, $this->notes($rows[1]));
        $this->assertContains('У собственника на площадке остаётся своё объявление', $this->notes($rows[1]));
        $this->assertContains('Это объявление есть у агентства: '.$company->name, $this->notes($rows[2]));
        $this->assertContains('У собственника на площадке остаётся своё объявление', $this->notes($rows[2]));
        $this->assertContains('Компания: '.$company->name, $this->notes($rows[2]));
    }

    /**
     * Строки под меткой владения, как их видит человек.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function notes(array $row): array
    {
        return array_map(fn (string $line): string => html_entity_decode($line, ENT_QUOTES), explode('<br>', (string) $row['ownership_notes']));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function notesStarting(array $row, string $prefix): array
    {
        return array_values(array_filter($this->notes($row), fn (string $line): bool => str_starts_with($line, $prefix)));
    }
}
