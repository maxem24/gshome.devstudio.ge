<?php

namespace App\RealEstate\Exceptions;

/**
 * 422 validation_failed по фильтрам: сайты не приняли фильтры или район не сходится с микрорайоном
 * (оба должны совпасть, с 29.10 — и в GET /listings, и в POST /search-jobs). Это ответ, а не баг.
 */
final class RealEstateSearchRejected extends RealEstateException
{
    private const PLACE = ['district_id', 'subdistrict_id'];

    /**
     * @param  array<string, list<string>>  $fields  error.fields: имя поля → тексты сервера
     */
    public function __construct(string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }

    /** error.fields как пришёл (список текстов или одна строка) → поле → список строк. */
    public static function fromFields(string $message, mixed $fields): self
    {
        $out = [];
        foreach (is_array($fields) ? $fields : [] as $name => $texts) {
            $out[(string) $name] = array_values(array_filter(is_array($texts) ? $texts : [$texts], fn (mixed $t): bool => is_string($t) && $t !== ''));
        }

        return new self($message, $out);
    }

    /** Отказ только по месту (район / микрорайон) — его тексты показываем человеку как есть. */
    public function isAboutPlace(): bool
    {
        return $this->fields !== [] && array_diff(array_keys($this->fields), self::PLACE) === [];
    }

    public function userMessage(): string
    {
        $place = array_intersect_key($this->fields, array_flip(self::PLACE));
        // Тексты сервера уже человеческие («Микрорайон «Самгори» (Исани-Самгори) не входит…»), но только по-русски:
        // POST /search-jobs языка не принимает. Остальным языкам интерфейса — наш перевод.
        $texts = array_values(array_unique(array_merge(...array_values($place))));
        if ($texts !== [] && app()->getLocale() === 'ru') {
            return implode(' ', $texts);
        }

        return $place !== [] ? __('gshome.search.rejected_district') : __('gshome.search.rejected');
    }
}
