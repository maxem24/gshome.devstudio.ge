<?php

namespace Tests\Feature\Crm;

use App\Crm\ClientProfile;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_rooms_range_is_cleaned(): void
    {
        $this->assertSame(['rooms_min' => 2, 'rooms_max' => 3], ClientProfile::clean(['rooms_min' => '2', 'rooms_max' => 3]));
        $this->assertSame(['rooms_min' => 3], ClientProfile::clean(['rooms_min' => 3, 'rooms_max' => 2]));
        $this->assertSame([], ClientProfile::clean(['rooms_min' => 0, 'rooms_max' => 21]));
        $this->assertArrayNotHasKey('rooms', ClientProfile::clean(['rooms' => '2']));
    }

    public function test_landlord_drops_listing_fields(): void
    {
        $this->assertSame(['note' => 'x'], ClientProfile::cleanLandlord([
            'price_min' => 100, 'price_max' => 600, 'subdistrict_ids' => [1], 'rooms_min' => 2, 'rooms_max' => 3, 'note' => 'x',
        ]));
    }

    public function test_rooms_lines(): void
    {
        $this->assertContains('Комнат: 2–3', ClientProfile::linesFor(['rooms_min' => 2, 'rooms_max' => 3]));
        $this->assertContains('Комнат: от 3', ClientProfile::linesFor(['rooms_min' => 3]));
        $this->assertContains('Комнат: 2', ClientProfile::linesFor(['rooms_min' => 2, 'rooms_max' => 2]));
    }

    public function test_split_rooms_migration(): void
    {
        $cases = [
            ['rooms' => '2'],
            ['rooms' => '2-3'],
            ['rooms' => '2–3'],
            ['rooms' => '3+'],
            ['rooms' => 'студия'],
            ['rooms' => 'много', 'note' => 'a'],
        ];
        $ids = [];
        foreach ($cases as $profile) {
            $client = Client::factory()->create();
            DB::table('clients')->where('id', $client->id)->update(['profile' => json_encode($profile, JSON_UNESCAPED_UNICODE)]);
            $ids[] = $client->id;
        }

        (require database_path('migrations/2026_11_18_131000_split_client_rooms.php'))->up();

        $got = array_map(fn (int $id): array => (array) Client::query()->findOrFail($id)->profile, $ids);
        $this->assertEquals(['rooms_min' => 2, 'rooms_max' => 2], $got[0]);
        $this->assertEquals(['rooms_min' => 2, 'rooms_max' => 3], $got[1]);
        $this->assertEquals(['rooms_min' => 2, 'rooms_max' => 3], $got[2]);
        $this->assertEquals(['rooms_min' => 3], $got[3]);
        $this->assertEquals(['rooms_min' => 1, 'rooms_max' => 1], $got[4]);
        $this->assertEquals(['note' => "a\nКомнат: много"], $got[5]);
    }

    /** Откат и повторный накат не превращают «до 3» в «ровно 3». */
    public function test_rooms_migration_round_trip_keeps_upper_bound_only(): void
    {
        $client = Client::factory()->create(['profile' => ['rooms_max' => 3]]);
        $migration = require database_path('migrations/2026_11_18_131000_split_client_rooms.php');

        $migration->down();
        $migration->up();

        $this->assertEquals(['rooms_min' => 1, 'rooms_max' => 3], $client->fresh()?->profile);
    }
}
