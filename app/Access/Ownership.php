<?php

namespace App\Access;

use App\Enums\OwnershipLabel;

/** Что смотрящий видит в блоке принадлежности объявления. Пусто — не положено. */
final readonly class Ownership
{
    public function __construct(
        public OwnershipLabel $label,
        public ?string $companyName = null,
        public ?string $contactName = null,
        public ?string $contactPhone = null,
    ) {}

    public static function other(): self
    {
        return new self(OwnershipLabel::OtherCompany);
    }
}
