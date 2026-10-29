<?php

namespace App\RealEstate\Exceptions;

/**
 * Ссылку не приняли: 422 по полю url (не тот сайт, страница не распознана, выдача не сужена)
 * или 503 без сборщика на связи. Тексты сервера уже на языке запроса (lang) — показываем как есть.
 */
final class RealEstateLinkRejected extends RealEstateException
{
    /** @param  list<string>  $texts */
    public function __construct(string $message, public readonly array $texts = [])
    {
        parent::__construct($message);
    }

    public function userMessage(): string
    {
        return $this->texts !== [] ? implode(' ', $this->texts) : __('gshome.link.rejected');
    }
}
