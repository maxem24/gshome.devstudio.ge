<?php

namespace Tests\Feature\RealEstate;

use App\Crm\Exceptions\CrmForbidden;
use App\Enums\Direction;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateBadRequest;
use App\RealEstate\Exceptions\RealEstateFiltersRequired;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\RealEstateClient;
use App\RealEstate\SiteSearchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class SiteSearchClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RealEstateFake::configure();
    }

    public function test_body_uses_arrays_numbers_and_skips_empty_and_forbidden_fields(): void
    {
        RealEstateFake::fake();
        $owner = User::factory()->owner()->create();

        $body = SiteSearchRequest::build($owner, [
            'deal' => 'sale', 'city_id' => '1', 'district_id' => ['3', '5'], 'subdistrict_id' => [],
            'property_type_id' => '2', 'price_usd_min' => '', 'price_usd_max' => '90000',
            'rooms' => ['2', '3'], 'area_min' => '40.5', 'area_max' => null,
            'phones' => '555', 'sort' => 'new', 'cursor' => 'x', 'per_page' => 50, 'source' => 'ss_ge', 'lang' => 'ru',
        ]);

        $this->assertSame([
            'deal_type_id' => [1], 'city_id' => 1, 'property_type_id' => 2, 'district_id' => [3, 5],
            'rooms' => [2, 3], 'price_usd_max' => 90000, 'area_min' => 40.5,
        ], $body);
    }

    public function test_sale_agent_is_limited_to_sale_and_forged_rent_throws(): void
    {
        RealEstateFake::fake();
        $lead = User::factory()->teamLead(direction: Direction::Sale)->create();

        $this->assertSame([1], SiteSearchRequest::build($lead, ['city_id' => 1])['deal_type_id']);
        $this->assertSame([1], SiteSearchRequest::build($lead, ['deal' => 'sale'])['deal_type_id']);

        $this->expectException(CrmForbidden::class);
        SiteSearchRequest::build($lead, ['deal' => 'rent']);
    }

    public function test_owner_without_choice_gets_both_deal_types(): void
    {
        RealEstateFake::fake();
        $owner = User::factory()->owner()->create();

        $this->assertSame([1, 2], SiteSearchRequest::build($owner, ['city_id' => 1])['deal_type_id']);
        $this->assertSame([2], SiteSearchRequest::build($owner, ['deal' => 'rent'])['deal_type_id']);
    }

    public function test_start_search_posts_json_and_returns_queued_status(): void
    {
        Http::fake(['*/search-jobs' => Http::response(['data' => $this->job('queued')], 202)]);

        $status = app(RealEstateClient::class)->startSearch(['city_id' => 1, 'district_id' => [3]]);

        $this->assertSame('queued', $status->status);
        $this->assertSame('uuid-1', $status->id);
        $this->assertFalse($status->isFinished());
        $this->assertSame('skipped', $status->sites['ss_ge']['status']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_ends_with($r->url(), '/search-jobs')
            && $r->hasHeader('Content-Type', 'application/json')
            && $r->data() === ['city_id' => 1, 'district_id' => [3]]);
    }

    public function test_start_search_may_return_done_from_cache_with_listing_ids(): void
    {
        Http::fake(['*/search-jobs' => Http::response(['data' => $this->job('done', [['id' => 7], ['id' => 9]])], 202)]);

        $status = app(RealEstateClient::class)->startSearch(['city_id' => 1]);

        $this->assertTrue($status->isFinished());
        $this->assertSame([7, 9], $status->listingIds);
    }

    public function test_filters_required_throws_dedicated_exception_without_down_marker(): void
    {
        Http::fake(['*/search-jobs' => Http::response(['error' => ['code' => 'filters_required', 'message' => 'Нужен фильтр']], 422)]);

        try {
            app(RealEstateClient::class)->startSearch([]);
            $this->fail('нет исключения');
        } catch (RealEstateFiltersRequired $e) {
            $this->assertNotSame('', $e->userMessage());
        }
        $this->assertFalse(Cache::has('real-estate:down'));
    }

    public function test_other_422_is_bad_request_and_429_is_rate_limited(): void
    {
        Http::fake(['*/search-jobs' => Http::sequence()
            ->push(['error' => ['code' => 'json_required']], 422)
            ->push(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '120'])]);
        $client = app(RealEstateClient::class);

        try {
            $client->startSearch(['city_id' => 1]);
            $this->fail('нет исключения');
        } catch (RealEstateBadRequest) {
        }
        try {
            $client->startSearch(['city_id' => 1]);
            $this->fail('нет исключения');
        } catch (RealEstateRateLimited $e) {
            $this->assertSame(120, $e->retryAfter);
        }
        $this->assertFalse(Cache::has('real-estate:down'));
    }

    public function test_search_status_done_has_sites_listings_and_is_not_cached(): void
    {
        Http::fake(['*/search-jobs/uuid-1*' => Http::sequence()
            ->push(['data' => $this->job('done', [['id' => 5]], truncated: true)])
            ->push(['data' => $this->job('searching')])]);
        $client = app(RealEstateClient::class);

        $done = $client->searchStatus('uuid-1');
        $this->assertTrue($done->isFinished());
        $this->assertTrue($done->truncated);
        $this->assertSame(3, $done->found);
        $this->assertSame([5], $done->listingIds);
        $this->assertSame(['status' => 'done', 'found' => 3, 'pages_done' => 2, 'message' => 'Готово'], $done->sites['myhome_ge']);

        $this->assertSame('searching', $client->searchStatus('uuid-1')->status);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && str_contains($r->url(), 'lang='));
    }

    public function test_search_status_404_is_bad_request(): void
    {
        Http::fake(['*/search-jobs/*' => Http::response(['error' => ['code' => 'not_found']], 404)]);

        $this->expectException(RealEstateBadRequest::class);
        app(RealEstateClient::class)->searchStatus('nope');
    }

    /**
     * @param  list<array<string, mixed>>  $listings
     * @return array<string, mixed>
     */
    private function job(string $status, array $listings = [], bool $truncated = false): array
    {
        return [
            'id' => 'uuid-1', 'status' => $status, 'message' => 'Поиск идёт', 'pages_done' => 0, 'found' => 3, 'truncated' => $truncated,
            'sites' => [
                'myhome_ge' => ['status' => $status === 'done' ? 'done' : 'queued', 'found' => 3, 'pages_done' => 2, 'message' => $status === 'done' ? 'Готово' : 'Поиск идёт'],
                'ss_ge' => ['status' => 'skipped', 'found' => 0, 'pages_done' => 0, 'message' => 'Для ss.ge нужен город или район'],
            ],
        ] + ($status === 'done' ? ['listings' => $listings] : []);
    }
}
