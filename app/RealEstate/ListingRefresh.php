<?php

namespace App\RealEstate;

use Illuminate\Support\Carbon;

/** Состояние актуализации объявления (ответ POST /listings/{id}/refresh и GET /listing-refreshes/{id}). */
final readonly class ListingRefresh
{
    /**
     * @param  list<ListingRefreshItem>  $items
     * @param  array<string, mixed>|null  $listing  сырая карточка (есть при done/stale)
     */
    public function __construct(
        public string $id,
        public string $status,
        public int $etaSeconds,
        public int $pollAfterMs,
        public bool $paused,
        public bool $truncated,
        public ?Carbon $deadlineAt,
        public array $items,
        public ?array $listing,
    ) {}

    /** done — всё проверено; stale — прошло 10 минут, не всё проверено. */
    public function finished(): bool
    {
        return in_array($this->status, ['done', 'stale'], true);
    }

    public function stale(): bool
    {
        return $this->status === 'stale';
    }

    /** Формат не тот — \UnexpectedValueException (клиент превратит в «API недоступен»). */
    public static function fromApi(mixed $data): self
    {
        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_string($data['status'] ?? null)) {
            throw new \UnexpectedValueException('нет id или status актуализации');
        }
        $deadline = $data['deadline_at'] ?? null;
        $listing = $data['listing'] ?? null;

        return new self(
            $data['id'],
            $data['status'],
            max(0, (int) ($data['eta_seconds'] ?? 0)),
            max(1000, (int) ($data['poll_after_ms'] ?? 2000)),
            (bool) ($data['paused'] ?? false),
            (bool) ($data['truncated'] ?? false),
            is_string($deadline) && $deadline !== '' ? Carbon::parse($deadline) : null,
            array_values(array_map(ListingRefreshItem::fromApi(...), is_array($data['items'] ?? null) ? $data['items'] : [])),
            is_array($listing) ? $listing : null,
        );
    }
}
