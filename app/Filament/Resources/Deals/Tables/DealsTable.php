<?php

namespace App\Filament\Resources\Deals\Tables;

use App\Access\OrgAccess;
use App\Crm\DealStage;
use App\Crm\DealStages;
use App\Enums\DealStatus;
use App\Filament\Pages\ListingView;
use App\Filament\Resources\Deals\Schemas\DealInfolist;
use App\Models\Deal;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class DealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'pin', 'agent', 'stageChanges',
                // @phpstan-ignore method.notFound (SoftDeletes добавляет withTrashed макросом)
                'client' => fn ($client) => $client->withTrashed(),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('client.first_name')
                    ->label(__('gshome.crm.deals.client'))
                    ->state(fn (Deal $record): string => DealInfolist::clientName($record)),
                TextColumn::make('pin.listing_id')
                    ->label(__('gshome.crm.deals.listing'))
                    ->prefix('№')
                    ->url(fn (Deal $record): ?string => $record->pin === null ? null : ListingView::getUrl(['listing' => $record->pin->listing_id])),
                TextColumn::make('agent.name')->label(__('gshome.crm.deals.agent')),
                TextColumn::make('stage')
                    ->label(__('gshome.crm.deals.stage'))
                    ->formatStateUsing(fn (string $state): string => DealStages::find($state)?->label() ?? $state),
                TextColumn::make('time_on_stage')
                    ->label(__('gshome.crm.deals.time_on_stage'))
                    ->state(fn (Deal $record): ?string => DealInfolist::timeOnStage($record))
                    ->placeholder('—'),
                TextColumn::make('status')->label(__('gshome.crm.deals.status'))->badge(),
                TextColumn::make('deleted_at')
                    ->label(__('gshome.crm.clients.archived'))
                    ->badge()
                    ->color('danger')
                    ->state(fn (Deal $record): ?string => $record->trashed() ? __('gshome.crm.clients.archived') : null),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('gshome.crm.deals.agent'))
                    ->options(fn (): array => self::agentOptions()),
                SelectFilter::make('stage')
                    ->label(__('gshome.crm.deals.stage'))
                    ->options(fn (): array => collect(DealStages::all())
                        ->mapWithKeys(fn (DealStage $s): array => [$s->key => $s->label()])
                        ->all()),
                SelectFilter::make('status')
                    ->label(__('gshome.crm.deals.status'))
                    ->options(DealStatus::class),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /** @return array<int, string> только видимые смотрящему люди */
    private static function agentOptions(): array
    {
        $viewer = Auth::user();
        if (! $viewer instanceof User) {
            return [];
        }

        /** @var array<int, string> $options */
        $options = OrgAccess::visibleUsers($viewer)->orderBy('name')->pluck('name', 'id')->all();

        return $options;
    }
}
