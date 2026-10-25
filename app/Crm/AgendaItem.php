<?php

namespace App\Crm;

use Carbon\CarbonInterface;

/**
 * Строка «Моих задач». Ссылку на запись строит экран по type и id, поэтому url здесь нет.
 */
final readonly class AgendaItem
{
    /** @param  'task'|'viewing'  $type */
    public function __construct(
        public string $type,
        public int $id,
        public string $title,
        public ?CarbonInterface $at,
        public bool $overdue,
    ) {}
}
