<?php

namespace App\Filament\RelationManagers;

use App\Crm\JournalQuery;
use App\Enums\Role;
use App\Filament\Pages\JournalPage;
use App\Models\Deal;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

/** Вкладка «Журнал» на карточке: записи про эту запись, только от видимых смотрящему людей. */
class JournalRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('gshome.crm.journal.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->role !== Role::Agent;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var User $viewer */
        $viewer = Auth::user();

        $owner = $this->getOwnerRecord();

        if ($owner instanceof Deal) {
            // На сделке видны и события показов: у них субъект — показ, а сделка лежит в properties.deal_id.
            $table->query(fn (): Builder => Activity::query()->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query->where('subject_type', $owner->getMorphClass())->where('subject_id', $owner->getKey()))
                ->orWhere('properties->deal_id', (string) $owner->getKey())));
        }

        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query): Builder => JournalQuery::restrict($query, $viewer)->with('causer'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('gshome.crm.journal.at'))->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('causer.name')->label(__('gshome.crm.journal.person')),
                TextColumn::make('event')->label(__('gshome.crm.journal.event'))->badge()
                    ->formatStateUsing(fn (?string $state): string => JournalPage::eventLabel($state)),
            ]);
    }
}
