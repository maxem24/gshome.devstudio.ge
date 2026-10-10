<?php

namespace App\RealEstate;

use App\Enums\Direction;
use App\Enums\SellerCategory;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;

/** Объявление из API Real Estate (только чтение). */
final readonly class Listing
{
    /**
     * @param  list<array{url: string, url_thumb: ?string, is_main: bool}>  $images
     * @param  list<string>  $amenities
     * @param  list<string>  $subwayStations
     * @param  list<string>  $clusterPhonesE164
     * @param  list<array{id: int, source: string, source_url: string, seller_category: SellerCategory, agency_name: ?string, contact_name: ?string, phone_e164: ?string, price_usd: ?float}>  $clusterListings
     */
    public function __construct(
        public int $id,
        public string $source,
        public string $sourceUrl,
        public ?string $dealTypeSlug,
        public ?string $dealTypeTitle,
        public ?string $propertyType,
        public ?string $city,
        public ?string $district,
        public ?string $address,
        public ?float $lat,
        public ?float $lng,
        public ?float $areaTotal,
        public ?int $rooms,
        public ?int $floor,
        public ?int $floorsTotal,
        public ?float $priceUsd,
        public ?float $priceGel,
        public bool $negotiable,
        public ?string $title,
        public ?string $description,
        public array $images,
        public array $amenities,
        public array $subwayStations,
        public SellerCategory $sellerCategory,
        public ?string $agencyName,
        public ?string $contactName,
        public ?string $contactPhone,
        public ?string $contactPhoneE164,
        public bool $hasViber,
        public bool $hasWhatsapp,
        public int $clusterListingsCount,
        public array $clusterPhonesE164,
        public array $clusterListings,
        public ?Carbon $sourceCreatedAt,
    ) {}

    /** @param  array<string, mixed>  $d */
    public static function fromApi(array $d, string $locale): self
    {
        $text = fn (?array $t): ?string => ($t[$locale] ?? null) ?: ($t['ka'] ?? null);
        $phone = $d['contact']['phone'] ?? null;
        $cluster = $d['cluster'] ?? null;

        return new self(
            id: (int) $d['id'],
            source: (string) $d['source'],
            sourceUrl: (string) $d['source_url'],
            dealTypeSlug: $d['deal_type']['slug'] ?? null,
            dealTypeTitle: $d['deal_type']['title'] ?? null,
            propertyType: $d['property_type']['title'] ?? null,
            city: $d['city']['title'] ?? null,
            district: $d['district']['title'] ?? null,
            address: $d['address'] ?? null,
            lat: isset($d['lat']) ? (float) $d['lat'] : null,
            lng: isset($d['lng']) ? (float) $d['lng'] : null,
            areaTotal: isset($d['area_total']) ? (float) $d['area_total'] : null,
            rooms: isset($d['rooms']) ? (int) $d['rooms'] : null,
            floor: isset($d['floor']) ? (int) $d['floor'] : null,
            floorsTotal: isset($d['floors_total']) ? (int) $d['floors_total'] : null,
            priceUsd: isset($d['price']['usd']) ? (float) $d['price']['usd'] : null,
            priceGel: isset($d['price']['gel']) ? (float) $d['price']['gel'] : null,
            negotiable: (bool) ($d['price']['negotiable'] ?? false),
            title: $text($d['title'] ?? null),
            description: $text($d['description'] ?? null),
            images: array_values(array_map(fn (array $i): array => [
                'url' => (string) $i['url'], 'url_thumb' => $i['url_thumb'] ?? null, 'is_main' => (bool) ($i['is_main'] ?? false),
            ], $d['images'] ?? [])),
            amenities: array_values(array_map(fn (array $a): string => (string) ($a['title'] ?? $a['slug'] ?? ''), $d['amenities'] ?? [])),
            subwayStations: array_values(array_map(
                fn (array $s): string => trim(($s['title'] ?? '').(isset($s['walk_minutes']) ? " ({$s['walk_minutes']} мин)" : '')),
                $d['subway_stations'] ?? [],
            )),
            sellerCategory: self::category($d['seller'] ?? []),
            agencyName: $d['seller']['agency_name'] ?? null,
            contactName: $d['contact']['name'] ?? null,
            contactPhone: $phone,
            contactPhoneE164: array_key_exists('phone_e164', $d['contact'] ?? [])
                ? $d['contact']['phone_e164']
                : PhoneNumber::tryNormalize($phone),
            hasViber: (bool) ($d['contact']['has_viber'] ?? false),
            hasWhatsapp: (bool) ($d['contact']['has_whatsapp'] ?? false),
            clusterListingsCount: (int) ($cluster['listings_count'] ?? 1),
            clusterPhonesE164: array_values($cluster['phones_e164'] ?? []),
            clusterListings: array_values(array_map(fn (array $m): array => [
                'id' => (int) $m['id'],
                'source' => (string) $m['source'],
                'source_url' => (string) $m['source_url'],
                'seller_category' => self::category($m['seller'] ?? []),
                'agency_name' => $m['seller']['agency_name'] ?? null,
                'contact_name' => $m['contact']['name'] ?? null,
                'phone_e164' => $m['contact']['phone_e164'] ?? null,
                'price_usd' => isset($m['price']['usd']) ? (float) $m['price']['usd'] : null,
            ], $cluster['listings'] ?? [])),
            sourceCreatedAt: isset($d['source_created_at']) ? Carbon::parse($d['source_created_at']) : null,
        );
    }

    /** @param  array<string, mixed>  $seller */
    private static function category(array $seller): SellerCategory
    {
        return match (true) {
            (bool) ($seller['is_owner'] ?? false) => SellerCategory::Owner,
            (bool) ($seller['is_agency'] ?? false) || filled($seller['agency_name'] ?? null) => SellerCategory::Agency,
            default => SellerCategory::Agent,
        };
    }

    public function direction(): ?Direction
    {
        return Direction::tryFrom((string) $this->dealTypeSlug);
    }

    public function priceLabel(): string
    {
        if ($this->priceUsd === null) {
            return 'цена не указана';
        }
        $amount = '$'.number_format($this->priceUsd, 0, '.', ' ');

        return $this->direction() === Direction::Rent ? "{$amount} / мес" : $amount;
    }

    public function mainThumb(): ?string
    {
        $main = collect($this->images)->firstWhere('is_main', true) ?? ($this->images[0] ?? null);

        return $main === null ? null : ($main['url_thumb'] ?? $main['url']);
    }

    public function facts(): string
    {
        return collect([
            $this->rooms !== null ? "{$this->rooms} комн." : null,
            $this->areaTotal !== null ? rtrim(rtrim(number_format($this->areaTotal, 1, '.', ' '), '0'), '.').' м²' : null,
            $this->floor !== null ? "{$this->floor}".($this->floorsTotal ? "/{$this->floorsTotal}" : '').' эт.' : null,
            $this->propertyType,
        ])->filter()->implode(' · ');
    }
}
