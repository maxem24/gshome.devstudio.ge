<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case Owner = 'owner';
    case CompanyOwner = 'company_owner';
    case TeamLead = 'team_lead';
    case Agent = 'agent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Владелец',
            self::CompanyOwner => 'Владелец компании',
            self::TeamLead => 'Тимлид',
            self::Agent => 'Сотрудник',
        };
    }

    /** Роли, которые состоят в команде (продажа или сдача). */
    public function hasTeam(): bool
    {
        return $this === self::TeamLead || $this === self::Agent;
    }
}
