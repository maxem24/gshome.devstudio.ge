<?php

namespace App\Crm;

use App\Models\Client;

/** Итог поиска клиента по номеру: new — номера нет, same — тот же человек, conflict — номер у другого. */
final readonly class ClientMatch
{
    /** @param 'new'|'same'|'conflict' $kind */
    public function __construct(
        public string $kind,
        public ?Client $existing,
        public string $phoneE164,
    ) {}
}
