<?php

namespace App\Filament\Resources\CompanyExchanges\Pages;

use App\Filament\Resources\CompanyExchanges\CompanyExchangeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCompanyExchanges extends ManageRecords
{
    protected static string $resource = CompanyExchangeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
