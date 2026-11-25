<?php

namespace Tests\Feature\Crm;

use App\Crm\Matching;
use App\Crm\MatchRow;
use App\Enums\ClientHousing;
use App\Enums\Direction;
use App\Models\Client;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MatchingTest extends TestCase
{
    use RefreshDatabase;

    private int $listingId = 1000;

    /**
     * @param  array<string, mixed>  $snap
     * @param  array<string, mixed>  $prefs
     * @param  array<string, mixed>  $extra
     */
    private function pin(array $snap = [], array $prefs = [], array $extra = []): ListingPin
    {
        $id = ++$this->listingId;

        $object = ListingObject::factory()->create([
            'listing_id' => $id, 'object_key' => 'l:'.$id, 'snapshot_at' => now(),
            ...array_replace(['subdistrict_id' => 101, 'price_usd' => 600, 'rooms' => 2, 'title' => 'Квартира'], $snap),
        ]);

        return $this->pinOn($object, $prefs, $extra);
    }

    /**
     * @param  array<string, mixed>  $prefs
     * @param  array<string, mixed>  $extra
     */
    private function pinOn(ListingObject $object, array $prefs = [], array $extra = []): ListingPin
    {
        return ListingPin::query()->create([
            'listing_id' => $object->listing_id, 'pin_key' => 'l'.$object->listing_id.'-'.++$this->listingId, 'object_id' => $object->id, 'direction' => Direction::Rent,
            'user_id' => User::factory()->create()->id, 'status' => ListingPin::ACTIVE, 'last_activity_at' => now(),
            'preferences' => $prefs, ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $profile */
    private function looking(array $profile, ?ClientHousing $housing = ClientHousing::Looking): Client
    {
        return Client::factory()->create(['profile' => $profile, 'housing' => $housing]);
    }

    private function one(ListingPin $pin): MatchRow
    {
        $rows = app(Matching::class)->clientsFor($pin);
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    public function test_full_match_is_100(): void
    {
        $pin = $this->pin([], ['occupants' => ['couple']]);
        $this->looking(['subdistrict_ids' => [101], 'price_min' => 500, 'price_max' => 700, 'occupants' => 'couple', 'rooms_min' => 2, 'rooms_max' => 2]);

        $row = $this->one($pin);
        $this->assertSame(100, $row->score);
        $this->assertTrue($row->enough);
        $this->assertSame([], $row->mismatches);
    }

    public function test_price_within_ten_percent_is_half(): void
    {
        $pin = $this->pin(['price_usd' => 650]);
        $this->looking(['subdistrict_ids' => [101], 'price_max' => 600]);

        $row = $this->one($pin);
        $this->assertContains('цена +$50', $row->mismatches);
        $this->assertSame((int) round(100 * (30 + 12.5) / 55), $row->score);
    }

    public function test_open_lower_bound(): void
    {
        $pin = $this->pin(['price_usd' => 300]);
        $this->looking(['subdistrict_ids' => [101], 'price_max' => 600]);

        $this->assertSame([], $this->one($pin)->mismatches);
    }

    public function test_unknown_does_not_penalize(): void
    {
        $pin = $this->pin(['subdistrict_id' => null, 'price_usd' => null], ['occupants' => ['couple']]);
        $this->looking(['subdistrict_ids' => [101], 'price_max' => 600, 'occupants' => 'couple']);

        $row = $this->one($pin);
        $this->assertSame([], $row->mismatches);
        $this->assertSame(100, $row->score);
    }

    public function test_landlord_without_preferences(): void
    {
        $pin = $this->pin();
        $this->looking(['subdistrict_ids' => [101], 'occupants' => 'couple', 'home' => ['pets']]);

        $row = $this->one($pin);
        $this->assertSame([], $row->mismatches);
    }

    public function test_pets_rejected_when_landlord_filled_home_without_pets(): void
    {
        $this->looking(['home' => ['pets']]);

        $this->assertContains('животные', $this->one($this->pin([], ['home' => ['furnished']]))->mismatches);
        $this->assertNotContains('животные', app(Matching::class)->clientsFor($this->pin([], ['home' => []]))[0]->mismatches);
    }

    public function test_occupants_rejected(): void
    {
        $pin = $this->pin([], ['occupants' => ['couple', 'small_family']]);
        $this->looking(['occupants' => 'two_male_students']);

        $this->assertContains('собственник против: Два студента', $this->one($pin)->mismatches);
    }

    public function test_opposite_home_items(): void
    {
        $pin = $this->pin([], ['home' => ['unfurnished', 'new_build']]);
        $this->looking(['home' => ['furnished', 'new_build', 'in_block']]);

        $row = $this->one($pin);
        $this->assertContains('нет: С мебелью', $row->mismatches);
    }

    public function test_rooms_one_off_is_half_and_people_over_limit(): void
    {
        $pin = $this->pin(['rooms' => 3], ['people' => 2]);
        $this->looking(['rooms_min' => 1, 'rooms_max' => 2, 'people' => 4]);

        $row = $this->one($pin);
        $this->assertContains('комнат 3', $row->mismatches);
        $this->assertContains('людей больше, чем допускает собственник', $row->mismatches);
        $this->assertSame((int) round(100 * 5 / 15), $row->score);
    }

    public function test_little_data_goes_below(): void
    {
        $pin = $this->pin([], ['occupants' => ['couple'], 'home' => ['furnished'], 'people' => 3]);
        $thin = $this->looking(['subdistrict_ids' => [101]]);
        $rich = $this->looking(['subdistrict_ids' => [101], 'price_max' => 600, 'occupants' => 'couple', 'rooms_min' => 2, 'people' => 2, 'home' => ['unfurnished']]);

        $rows = app(Matching::class)->clientsFor($pin);
        $this->assertSame([$rich->id, $thin->id], array_map(fn (MatchRow $r): int => $r->other->id, $rows));
        $this->assertFalse($rows[1]->enough);
        $this->assertSame(100, $rows[1]->score);
        $this->assertTrue($rows[0]->enough);
    }

    public function test_string_numbers_do_not_break_query(): void
    {
        $pin = $this->pin();
        $client = $this->looking(['subdistrict_ids' => [101]]);
        DB::table('clients')->where('id', $client->id)->update(['profile' => json_encode(['subdistrict_ids' => [101], 'price_max' => '600$', 'rooms_min' => 'два'])]);

        $row = $this->one($pin);
        $this->assertSame([], $row->mismatches);
        $this->assertSame(100, $row->score);
    }

    public function test_candidates_filter(): void
    {
        $pin = $this->pin();
        $active = $this->looking(['subdistrict_ids' => [101]]);
        $this->looking(['subdistrict_ids' => [101]], ClientHousing::Renting);
        $this->looking(['subdistrict_ids' => [101]])->delete();

        $this->assertSame([$active->id], array_map(fn (MatchRow $r): int => $r->other->id, app(Matching::class)->clientsFor($pin)));

        $this->pin([], [], ['status' => ListingPin::CLOSED, 'closed_reason' => 'released', 'closed_at' => now()]);
        $this->pin([], [], ['direction' => Direction::Sale]);
        $this->pin()->delete();
        $owner = User::factory()->owner()->create();
        $this->assertSame([$pin->id], array_map(fn (MatchRow $r): int => $r->other->id, app(Matching::class)->pinsFor($active, $owner)));
    }

    public function test_limit_and_order(): void
    {
        $pin = $this->pin();
        foreach (range(1, 12) as $i) {
            $this->looking(['subdistrict_ids' => [101], 'price_max' => 600 - $i * 10]);
        }

        $rows = app(Matching::class)->clientsFor($pin);
        $this->assertCount(10, $rows);
        $scores = array_map(fn (MatchRow $r): ?int => $r->score, $rows);
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    public function test_offers_need_rent_direction(): void
    {
        $this->pin();
        $client = $this->looking(['subdistrict_ids' => [101]]);
        $saleAgent = User::factory()->agent(User::factory()->teamLead(null, Direction::Sale)->create())->create();

        $this->assertSame([], app(Matching::class)->pinsFor($client, $saleAgent));
    }

    public function test_client_sees_one_row_per_object_with_two_agents(): void
    {
        $first = $this->pin();
        $this->pinOn($first->object);
        $client = $this->looking(['subdistrict_ids' => [101]]);

        $rows = app(Matching::class)->pinsFor($client, User::factory()->owner()->create());

        $this->assertCount(1, $rows);
        $this->assertSame($first->id, $rows[0]->other->id);
    }

    public function test_viewer_own_preferences_preferred(): void
    {
        $first = $this->pin([], ['occupants' => ['family']]);
        $mine = $this->pinOn($first->object, ['occupants' => ['couple']]);
        $client = $this->looking(['subdistrict_ids' => [101], 'occupants' => 'couple']);

        $rows = app(Matching::class)->pinsFor($client, $mine->holder);

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows[0]->other->id);
        $this->assertSame(100, $rows[0]->score);
    }

    public function test_closed_object_is_not_offered(): void
    {
        $pin = $this->pin();
        $client = $this->looking(['subdistrict_ids' => [101]]);
        $pin->object?->update(['status' => ListingObject::CLOSED, 'closed_reason' => 'abandoned', 'closed_at' => now()]);

        $this->assertSame([], app(Matching::class)->pinsFor($client, User::factory()->owner()->create()));
        $this->assertSame([], app(Matching::class)->clientsFor($pin));
    }
}
