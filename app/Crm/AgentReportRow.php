<?php

namespace App\Crm;

use App\Models\User;

/** Строка отчёта руководителя: цифры одного сотрудника за период. */
final readonly class AgentReportRow
{
    /**
     * @param  array<string, string>  $earnings  валюта ⇒ сумма (своя часть и доля партнёра)
     * @param  array<string, int>  $avgStageSeconds  ключ этапа ⇒ среднее время на этапе, секунды
     * @param  list<int>  $stalePinIds  id застывших закреплений
     */
    public function __construct(
        public User $user,
        public int $pinsActive,
        public int $dealsOpened,
        public int $viewingsHeld,
        public int $dealsWon,
        public int $dealsWonAsPartner,
        public int $dealsLost,
        public array $earnings,
        public array $avgStageSeconds,
        public int $stalePins,
        public array $stalePinIds,
    ) {}
}
