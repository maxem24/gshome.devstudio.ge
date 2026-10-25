<?php

namespace App\Filament\Resources\Deals\Schemas;

use App\Crm\DealStages;
use App\Filament\Pages\ListingView;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/** Карточка сделки: клиент, объект, этап с историей смен и временем на этапе. */
class DealInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('client_name')
                ->label(__('gshome.crm.deals.client'))
                ->state(fn (Deal $record): string => self::clientName($record))
                ->url(fn (Deal $record): ?string => self::clientUrl($record)),
            TextEntry::make('pin.listing_id')
                ->label(__('gshome.crm.deals.listing'))
                ->prefix('№')
                ->url(fn (Deal $record): ?string => $record->pin === null ? null : ListingView::getUrl(['listing' => $record->pin->listing_id])),
            TextEntry::make('agent.name')->label(__('gshome.crm.deals.agent')),
            TextEntry::make('status')->label(__('gshome.crm.deals.status'))->badge(),
            TextEntry::make('stage')
                ->label(__('gshome.crm.deals.stage'))
                ->formatStateUsing(fn (string $state): string => DealStages::find($state)?->label() ?? $state),
            TextEntry::make('time_on_stage')
                ->label(__('gshome.crm.deals.time_on_stage'))
                ->state(fn (Deal $record): ?string => self::timeOnStage($record))
                ->placeholder('—'),
            TextEntry::make('amount')
                ->label(__('gshome.crm.deals.amount'))
                ->state(fn (Deal $record): string => trim($record->amount.' '.$record->currency))
                ->visible(fn (Deal $record): bool => $record->amount !== null),
            TextEntry::make('lost_reason')
                ->label(__('gshome.crm.deals.reason'))
                ->visible(fn (Deal $record): bool => $record->lost_reason !== null),
            TextEntry::make('history')
                ->label(__('gshome.crm.deals.history'))
                ->state(fn (Deal $record): array => self::history($record))
                ->listWithLineBreaks()
                ->columnSpanFull(),
            TextEntry::make('deleted_at')
                ->label(__('gshome.crm.clients.archived'))
                ->dateTime('d.m.Y H:i')
                ->visible(fn (Deal $record): bool => $record->trashed()),
        ])->columns(2);
    }

    public static function clientName(Deal $deal): string
    {
        $client = $deal->client;

        return $client === null ? '—' : trim($client->first_name.' '.$client->last_name);
    }

    /** Архивного клиента агент открыть не может: имя показываем, ссылку — нет. */
    private static function clientUrl(Deal $deal): ?string
    {
        $client = $deal->client;
        $viewer = Auth::user();
        if (! $client instanceof Client || ! $viewer instanceof User) {
            return null;
        }
        $visible = Client::query()->visibleTo($viewer)->whereKey($client->id)->exists();

        return $visible ? ClientResource::getUrl('view', ['record' => $client]) : null;
    }

    /** Сколько сделка стоит на текущем этапе; у закрытой сделки не считаем. */
    public static function timeOnStage(Deal $deal): ?string
    {
        if (! $deal->isOpen()) {
            return null;
        }
        $last = $deal->stageChanges->sortBy('changed_at')->last();

        return $last === null ? null : self::span($last->changed_at, now());
    }

    /** @return list<string> «Созвон → Показ назначен · 12.10.2026 14:00 · на прошлом этапе 2 дн.» */
    public static function history(Deal $deal): array
    {
        $changes = $deal->stageChanges()->orderBy('changed_at')->orderBy('id')->get()->values();
        $rows = [];
        foreach ($changes as $i => $change) {
            /** @var DealStageChange $change */
            $from = $change->from_stage === null ? null : (DealStages::find($change->from_stage)?->label() ?? $change->from_stage);
            $to = DealStages::find($change->to_stage)?->label() ?? $change->to_stage;
            $row = ($from === null ? '' : $from.' → ').$to.' · '.$change->changed_at->timezone(config('crm.timezone'))->format('d.m.Y H:i');
            // Сколько пробыли на этом этапе: до следующей смены, а у последней — пока сделка открыта.
            $next = $changes[$i + 1]->changed_at ?? ($deal->isOpen() ? now() : null);
            if ($next !== null) {
                $row .= ' · '.self::span($change->changed_at, $next);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function span(CarbonInterface $from, CarbonInterface $to): string
    {
        return $from->diffForHumans($to, CarbonInterface::DIFF_ABSOLUTE, true, 2);
    }
}
