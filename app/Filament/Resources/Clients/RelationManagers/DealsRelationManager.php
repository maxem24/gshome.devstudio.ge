<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Crm\DealStages;
use App\Filament\Pages\ListingView;
use App\Models\Deal;
use App\Models\Viewing;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** История клиента: все его сделки с показами — всем ролям, только чтение (решение владельца). */
class DealsRelationManager extends RelationManager
{
    protected static string $relationship = 'deals';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('gshome.crm.clients.history.title');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['pin', 'agent', 'viewings']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('pin.listing_id')
                    ->label(__('gshome.crm.clients.history.listing'))
                    ->prefix('№')
                    ->url(fn (Deal $record): ?string => $record->pin === null ? null : ListingView::getUrl(['listing' => $record->pin->listing_id])),
                TextColumn::make('agent.name')->label(__('gshome.crm.clients.history.agent')),
                TextColumn::make('stage')
                    ->label(__('gshome.crm.clients.history.stage'))
                    ->formatStateUsing(fn (string $state): string => DealStages::find($state)?->label() ?? $state),
                TextColumn::make('status')->label(__('gshome.crm.clients.history.status'))->badge(),
                TextColumn::make('viewings')
                    ->label(__('gshome.crm.clients.history.viewings'))
                    ->state(fn (Deal $record): array => $record->viewings
                        ->sortBy('scheduled_at')
                        ->map(fn (Viewing $v): string => $v->scheduled_at->format('d.m.Y H:i').' — '
                            .($v->outcome?->getLabel() ?? __('gshome.crm.clients.history.no_outcome')))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('—'),
            ]);
    }
}
