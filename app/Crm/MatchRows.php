<?php

namespace App\Crm;

use App\Filament\Pages\ListingView;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\ListingPin;
use App\Models\User;

/** Строки подбора пар для показа: обе карточки рисуют один и тот же список (filament.crm.matches). */
final class MatchRows
{
    /** @return list<array{score: ?int, enough: bool, label: string, sub: ?string, url: string, mismatches: list<string>}> */
    public static function forPin(ListingPin $pin): array
    {
        $out = [];
        foreach (app(Matching::class)->clientsFor($pin) as $row) {
            /** @var Client $client */
            $client = $row->other;
            $out[] = self::row($row, trim($client->first_name.' '.$client->last_name), $client->phone_e164, ClientResource::getUrl('view', ['record' => $client]));
        }

        return $out;
    }

    /** @return list<array{score: ?int, enough: bool, label: string, sub: ?string, url: string, mismatches: list<string>}> */
    public static function forClient(Client $client, User $viewer): array
    {
        $out = [];
        foreach (app(Matching::class)->pinsFor($client, $viewer) as $row) {
            /** @var ListingPin $pin */
            $pin = $row->other;
            $name = app(Pins::class)->holderLabel($pin, $viewer);
            $sub = collect([
                $pin->object->price_usd === null ? null : '$'.DealIntake::display((string) $pin->object->price_usd),
                $name === null ? __('gshome.crm.matching.in_work_other') : __('gshome.crm.matching.in_work_by', ['name' => $name]),
            ])->filter()->implode(' · ');
            $out[] = self::row($row, $pin->object->title ?? '№'.$pin->listing_id, $sub, ListingView::getUrl(['listing' => $pin->listing_id]));
        }

        return $out;
    }

    /** @return array{score: ?int, enough: bool, label: string, sub: ?string, url: string, mismatches: list<string>} */
    private static function row(MatchRow $row, string $label, ?string $sub, string $url): array
    {
        return ['score' => $row->score, 'enough' => $row->enough, 'label' => $label, 'sub' => $sub, 'url' => $url, 'mismatches' => $row->mismatches];
    }
}
