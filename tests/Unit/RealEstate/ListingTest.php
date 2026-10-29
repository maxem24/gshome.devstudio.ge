<?php

namespace Tests\Unit\RealEstate;

use App\Enums\Direction;
use App\Enums\SellerCategory;
use App\RealEstate\Listing;
use Tests\Support\RealEstateFake;
use Tests\TestCase;

class ListingTest extends TestCase
{
    public function test_parses_core_fields(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(), 'ru');

        $this->assertSame(13, $l->id);
        $this->assertSame(Direction::Sale, $l->direction());
        $this->assertSame('Квартира в Ваке', $l->title);
        $this->assertSame('+995555123456', $l->contactPhoneE164);
        $this->assertSame(SellerCategory::Owner, $l->sellerCategory);
        $this->assertSame([], $l->clusterPhonesE164);
        $this->assertSame(1, $l->clusterListingsCount);
        $this->assertSame('https://re.test/storage/1_t.webp', $l->mainThumb());
    }

    public function test_partial_flag_defaults_to_false_and_is_read_from_api(): void
    {
        $this->assertFalse(Listing::fromApi(RealEstateFake::listing(), 'ru')->partial);
        $this->assertTrue(Listing::fromApi(RealEstateFake::listing(['partial' => true]), 'ru')->partial);
    }

    public function test_match_of_listing_and_cluster_copies(): void
    {
        $soft = ['type' => 'soft', 'confidence' => 70, 'missing' => ['rooms'], 'evidence' => ['price'], 'explanation' => 'Комнат нет у одной копии, совпала цена.'];
        $l = Listing::fromApi(RealEstateFake::listing([
            'match' => ['type' => 'full', 'confidence' => 100, 'missing' => [], 'evidence' => [], 'explanation' => null],
            'cluster' => ['id' => 5, 'match' => 'soft', 'min_confidence' => 70, 'listings_count' => 2, 'phones_e164' => [],
                'listings' => [['id' => 41, 'source' => 'myhome_ge', 'seller' => [], 'contact' => [], 'match' => $soft]]],
        ]), 'ru');

        $this->assertFalse($l->isSoftMatch());
        $this->assertSame(['type' => 'full', 'confidence' => 100, 'explanation' => null], $l->match);
        $this->assertSame(['type' => 'soft', 'confidence' => 70, 'explanation' => 'Комнат нет у одной копии, совпала цена.'], $l->clusterListings[0]['match']);
        $this->assertNull(Listing::fromApi(RealEstateFake::listing(), 'ru')->match);
    }

    public function test_title_falls_back_to_georgian(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['title' => ['ru' => null], 'description' => ['ru' => null]]), 'ru');

        $this->assertSame('ბინა ვაკეში', $l->title);
        $this->assertSame('აღწერა', $l->description);
    }

    public function test_masked_phone_has_no_e164(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['contact' => ['phone' => '555402***', 'phone_e164' => null]]), 'ru');

        $this->assertNull($l->contactPhoneE164);
    }

    public function test_old_api_without_e164_is_normalized_here(): void
    {
        $data = RealEstateFake::listing();
        unset($data['contact']['phone_e164']);

        $this->assertSame('+995555123456', Listing::fromApi($data, 'ru')->contactPhoneE164);
    }

    public function test_agency_name_without_flag_is_agency(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['seller' => ['is_owner' => false, 'is_agency' => false, 'agency_name' => 'ელსერვისი']]), 'ru');

        $this->assertSame(SellerCategory::Agency, $l->sellerCategory);
    }

    public function test_neither_owner_nor_agency_is_agent(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['seller' => ['is_owner' => false, 'is_agency' => false, 'agency_name' => null]]), 'ru');

        $this->assertSame(SellerCategory::Agent, $l->sellerCategory);
    }

    public function test_cluster_phones_and_members(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['cluster' => [
            'id' => 5, 'listings_count' => 2, 'phones_e164' => ['+995555123456', '+995555614795'],
            'listings' => [['id' => 55, 'source' => 'ss_ge', 'source_url' => 'https://x/55', 'is_primary' => false,
                'seller' => ['is_owner' => false, 'is_agency' => false, 'agency_name' => null],
                'contact' => ['name' => 'Агент', 'phone' => '555614795', 'phone_e164' => '+995555614795', 'has_viber' => false, 'has_whatsapp' => false],
                'price' => ['usd' => 89000.0, 'gel' => 240300.0], 'updated_at' => '2026-10-07T10:00:00+00:00']],
        ]]), 'ru');

        $this->assertSame(['+995555123456', '+995555614795'], $l->clusterPhonesE164);
        $this->assertSame(2, $l->clusterListingsCount);
        $this->assertSame(55, $l->clusterListings[0]['id']);
        $this->assertSame('+995555614795', $l->clusterListings[0]['phone_e164']);
    }

    public function test_cluster_id_is_read(): void
    {
        $this->assertNull(Listing::fromApi(RealEstateFake::listing(['cluster' => null]), 'ru')->clusterId);
        $this->assertSame(5, Listing::fromApi(RealEstateFake::listing(['cluster' => ['id' => 5, 'listings_count' => 1]]), 'ru')->clusterId);
    }

    public function test_price_label_by_direction(): void
    {
        $this->assertSame('$90 000', Listing::fromApi(RealEstateFake::listing(), 'ru')->priceLabel());
        $rent = Listing::fromApi(RealEstateFake::listing(['deal_type' => ['id' => 2, 'slug' => 'rent'], 'price' => ['usd' => 800.0]]), 'ru');
        $this->assertSame('$800 / мес', $rent->priceLabel());
        $this->assertSame('цена не указана', Listing::fromApi(RealEstateFake::listing(['price' => ['usd' => null]]), 'ru')->priceLabel());
    }

    public function test_non_http_urls_are_dropped(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing([
            'source_url' => 'javascript:alert(1)',
            'images' => [['url' => 'javascript:alert(2)', 'url_thumb' => 'data:text/html,x', 'is_main' => true]],
            'cluster' => ['listings' => [['id' => 55, 'source' => 'ss_ge', 'source_url' => 'JavaScript:alert(3)']]],
        ]), 'ru');

        $this->assertNull($l->sourceUrl);
        $this->assertSame([], $l->images);
        $this->assertNull($l->clusterListings[0]['source_url']);
    }

    public function test_http_urls_stay_and_bad_thumb_becomes_null(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing([
            'source_url' => 'https://home.ss.ge/13',
            'images' => [['url' => 'https://re.test/a.webp', 'url_thumb' => 'javascript:x', 'is_main' => true]],
        ]), 'ru');

        $this->assertSame('https://home.ss.ge/13', $l->sourceUrl);
        $this->assertSame('https://re.test/a.webp', $l->images[0]['url']);
        $this->assertNull($l->images[0]['url_thumb']);
    }

    public function test_studio_instead_of_zero_rooms(): void
    {
        $l = Listing::fromApi(RealEstateFake::listing(['rooms' => 0]), 'ru');

        $this->assertStringStartsWith('студия', $l->facts());
        $this->assertStringNotContainsString('0 комн.', $l->facts());
    }
}
