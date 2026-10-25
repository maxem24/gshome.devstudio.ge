<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ClientTag: string implements HasLabel
{
    case Buyer = 'buyer';
    case Tenant = 'tenant';
    case Seller = 'seller';
    case Landlord = 'landlord';

    public function getLabel(): string
    {
        return __('gshome.crm.client_tag.'.$this->value);
    }
}
