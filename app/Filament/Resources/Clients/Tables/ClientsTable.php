<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Models\Client;
use App\Support\PhoneNumber;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Номер вводят с пробелами: делить запрос на слова нельзя.
            ->splitSearchTerms(false)
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('first_name')
                    ->label(__('gshome.crm.clients.name'))
                    ->state(fn (Client $record): string => trim($record->first_name.' '.$record->last_name))
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchName($query, $search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('first_name', $direction)->orderBy('last_name', $direction)),
                TextColumn::make('phone_e164')
                    ->label(__('gshome.crm.clients.phone'))
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchPhone($query, $search)),
                TextColumn::make('reliability')
                    ->label(__('gshome.crm.clients.reliability'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ClientForm::reliabilityLabel($state)),
                TextColumn::make('tags')
                    ->label(__('gshome.crm.clients.tags'))
                    ->badge()
                    ->state(fn (Client $record): array => $record->tagEnums())
                    ->toggleable(),
                TextColumn::make('deleted_at')
                    ->label(__('gshome.crm.clients.archived'))
                    ->badge()
                    ->color('danger')
                    ->state(fn (Client $record): ?string => $record->trashed() ? __('gshome.crm.clients.archived') : null),
                TextColumn::make('created_at')
                    ->label(__('gshome.crm.clients.created_at'))
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /**
     * Телефон в любом виде: точное совпадение после нормализации плюс вхождение цифр,
     * чтобы находился и обрывок номера.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private static function searchPhone(Builder $query, string $search): Builder
    {
        $exact = PhoneNumber::tryNormalize($search);
        $digits = preg_replace('/\D+/', '', $search) ?? '';

        return $query->where(function (Builder $inner) use ($exact, $digits): void {
            $inner->whereRaw('false');
            if ($exact !== null) {
                $inner->orWhere('phone_e164', $exact);
            }
            if (strlen($digits) >= 3) {
                $inner->orWhere('phone_e164', 'like', '%'.$digits.'%');
            }
        });
    }

    /**
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    private static function searchName(Builder $query, string $search): Builder
    {
        $like = '%'.addcslashes(trim($search), '%_\\').'%';

        return $query->where(function (Builder $inner) use ($like): void {
            $inner->where('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhereRaw("(first_name || ' ' || coalesce(last_name, '')) ilike ?", [$like]);
        });
    }
}
