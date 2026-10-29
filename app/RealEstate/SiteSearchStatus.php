<?php

namespace App\RealEstate;

/** Состояние поиска на сайтах (ответ POST/GET /search-jobs). Копии карточек не храним — только id. */
final readonly class SiteSearchStatus
{
    /**
     * @param  array<string, array{status: string, found: int, pages_done: int, message: string, until?: string, note?: string}>  $sites  note — сайт искал не по всем выбранным микрорайонам (тот же текст в конце message)
     * @param  list<int>  $listingIds
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $message,
        public int $found,
        public bool $truncated,
        public array $sites,
        public array $listingIds,
    ) {}

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed', 'cancelled'], true);
    }

    /** Формат не тот — \UnexpectedValueException (клиент превратит в «API недоступен»). */
    public static function fromApi(mixed $data): self
    {
        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_string($data['status'] ?? null)) {
            throw new \UnexpectedValueException('нет id или status поиска');
        }

        $sites = [];
        foreach (is_array($data['sites'] ?? null) ? $data['sites'] : [] as $key => $site) {
            if (! is_array($site)) {
                continue;
            }
            $sites[(string) $key] = [
                'status' => (string) ($site['status'] ?? ''),
                'found' => (int) ($site['found'] ?? 0),
                'pages_done' => (int) ($site['pages_done'] ?? 0),
                'message' => (string) ($site['message'] ?? ''),
            ] + (isset($site['until']) ? ['until' => (string) $site['until']] : [])
                + (is_string($site['note'] ?? null) && $site['note'] !== '' ? ['note' => $site['note']] : []);
        }

        $ids = [];
        foreach (is_array($data['listings'] ?? null) ? $data['listings'] : [] as $listing) {
            if (is_array($listing) && isset($listing['id'])) {
                $ids[] = (int) $listing['id'];
            }
        }

        return new self(
            $data['id'],
            $data['status'],
            (string) ($data['message'] ?? ''),
            (int) ($data['found'] ?? 0),
            (bool) ($data['truncated'] ?? false),
            $sites,
            $ids,
        );
    }
}
