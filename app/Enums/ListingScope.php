<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Пять кнопок над списком. Это не направление сделки. */
enum ListingScope: string implements HasLabel
{
    case All = 'all';
    case Owners = 'owners';
    case Agencies = 'agencies';
    case OurAgency = 'our_agency';
    case Mine = 'mine';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => __('gshome.listings.scope_all'),
            self::Owners => __('gshome.listings.scope_owners'),
            self::Agencies => __('gshome.listings.scope_agencies'),
            self::OurAgency => __('gshome.listings.scope_our_agency'),
            self::Mine => __('gshome.listings.scope_mine'),
        };
    }
}
