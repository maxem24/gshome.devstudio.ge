<?php

namespace App\RealEstate;

use Illuminate\Support\Carbon;

/** Одна копия объявления в актуализации (элемент items[] ответа /listing-refreshes). */
final readonly class ListingRefreshItem
{
    public const OUTCOMES = ['pending', 'refreshed', 'recent', 'removed', 'failed'];

    public function __construct(
        public int $id,
        public string $source,
        public ?string $sourceUrl,
        public string $status,
        public string $outcome,
        public ?Carbon $checkedAt,
        public ?Carbon $deactivatedAt,
        public ?string $error,
        public ?Carbon $pausedUntil,
    ) {}

    /** Формат не тот — \UnexpectedValueException; неизвестный outcome считаем failed, страницу не роняем. */
    public static function fromApi(mixed $data): self
    {
        if (! is_array($data) || ! isset($data['id']) || ! is_string($data['source'] ?? null)) {
            throw new \UnexpectedValueException('нет id или source копии');
        }
        $outcome = is_string($data['outcome'] ?? null) && in_array($data['outcome'], self::OUTCOMES, true) ? $data['outcome'] : 'failed';
        $date = fn (mixed $v): ?Carbon => is_string($v) && $v !== '' ? Carbon::parse($v) : null;
        $url = $data['source_url'] ?? null;

        return new self(
            (int) $data['id'],
            $data['source'],
            is_string($url) && in_array(strtolower((string) parse_url(trim($url), PHP_URL_SCHEME)), ['http', 'https'], true) ? trim($url) : null,
            (string) ($data['status'] ?? 'active'),
            $outcome,
            $date($data['checked_at'] ?? null),
            $date($data['deactivated_at'] ?? null),
            is_string($data['error'] ?? null) && trim($data['error']) !== '' ? trim($data['error']) : null,
            $date($data['paused_until'] ?? null),
        );
    }
}
