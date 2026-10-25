<?php

namespace App\Crm;

/** Этап сделки. Ключ неизменен, подписи и порядок настраивает владелец. */
final readonly class DealStage
{
    /**
     * @param  array{ru: string, en: string, ka: string}  $labels
     * @param  'normal'|'won'|'lost'  $kind
     */
    public function __construct(
        public string $key,
        public array $labels,
        public int $order,
        public bool $hidden,
        public string $kind,
    ) {}

    /** Подпись на языке интерфейса; пустая — берём русскую. */
    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $label = trim($this->labels[$locale] ?? '');

        return $label !== '' ? $label : $this->labels['ru'];
    }
}
