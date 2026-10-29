<?php

namespace App\RealEstate;

/**
 * Ответ POST /imports и GET /imports/{id}: ссылка на объявление (kind=listing) или на выдачу сайта (kind=search).
 * У выдачи внутри обычный поиск — дальше он живёт как ListingSearch.
 */
final readonly class LinkImport
{
    public const LISTING = 'listing';

    public const SEARCH = 'search';

    /**
     * @param  array{deal_type_id: ?int, property_type_id: ?int, city_id: ?int}  $filters  что Real Estate понял из ссылки выдачи
     */
    public function __construct(
        public string $kind,
        public ?string $id,
        public string $source,
        public string $url,
        public string $status,
        public ?int $listingId,
        public string $message,
        public array $filters,
        public ?SiteSearchStatus $search,
    ) {}

    public function isSearch(): bool
    {
        return $this->kind === self::SEARCH;
    }

    /** Объявление уже в базе Real Estate — карточку можно открыть. */
    public function ready(): bool
    {
        return $this->kind === self::LISTING && $this->status === 'done' && $this->listingId !== null;
    }

    public function finished(): bool
    {
        return in_array($this->status, ['done', 'failed', 'cancelled'], true);
    }

    /** Формат не тот — \UnexpectedValueException (клиент превратит в «API недоступен»). */
    public static function fromApi(mixed $data): self
    {
        $kind = is_array($data) ? ($data['kind'] ?? null) : null;
        if (! is_array($data) || ! in_array($kind, [self::LISTING, self::SEARCH], true)) {
            throw new \UnexpectedValueException('нет kind импорта');
        }

        $search = null;
        if ($kind === self::SEARCH) {
            $search = SiteSearchStatus::fromApi($data['search'] ?? null);
        } elseif (! is_string($data['id'] ?? null) || ! is_string($data['status'] ?? null)) {
            throw new \UnexpectedValueException('нет id или status импорта');
        }

        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        $id = fn (string $key): ?int => is_numeric($filters[$key] ?? null) ? (int) $filters[$key] : null;

        return new self(
            $kind,
            $search->id ?? (string) $data['id'],
            (string) ($data['source'] ?? ''),
            (string) ($data['url'] ?? ''),
            $search->status ?? (string) $data['status'],
            is_numeric($data['listing_id'] ?? null) ? (int) $data['listing_id'] : null,
            $search->message ?? (string) ($data['message'] ?? ''),
            ['deal_type_id' => $id('deal_type_id'), 'property_type_id' => $id('property_type_id'), 'city_id' => $id('city_id')],
            $search,
        );
    }
}
