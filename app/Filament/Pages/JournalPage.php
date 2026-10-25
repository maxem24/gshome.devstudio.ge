<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Crm\Journal;
use App\Crm\JournalQuery;
use App\Enums\Role;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use ReflectionClass;
use Spatie\Activitylog\Models\Activity;

/** Журнал действий: руководитель видит записи своих людей. Агент — нет. */
class JournalPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.journal';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $slug = 'journal';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return in_array(Auth::user()?->role, [Role::TeamLead, Role::CompanyOwner, Role::Owner], true);
    }

    public static function getNavigationLabel(): string
    {
        return __('gshome.crm.journal.title');
    }

    public function getTitle(): string
    {
        return __('gshome.crm.journal.title');
    }

    /** @return array<string, string> событие ⇒ подпись */
    public static function eventOptions(): array
    {
        $events = new ReflectionClass(Journal::class)->getConstants();

        return collect($events)
            ->filter(fn ($v): bool => is_string($v))
            ->mapWithKeys(fn (string $e): array => [$e => self::eventLabel($e)])
            ->all();
    }

    public static function eventLabel(?string $event): string
    {
        return $event === null ? '—' : __('gshome.crm.journal.events.'.$event);
    }

    public function table(Table $table): Table
    {
        /** @var User $viewer */
        $viewer = Auth::user();

        return $table
            ->query(fn (): Builder => JournalQuery::visibleTo($viewer)->with('causer'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('gshome.crm.journal.at'))->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('causer.name')->label(__('gshome.crm.journal.person')),
                TextColumn::make('event')->label(__('gshome.crm.journal.event'))->badge()
                    ->formatStateUsing(fn (?string $state): string => self::eventLabel($state)),
                TextColumn::make('listing')->label(__('gshome.crm.journal.listing'))
                    ->getStateUsing(fn (Activity $r): ?string => ($id = $r->properties->get('listing_id')) === null ? null : '№'.$id)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('causer_id')->label(__('gshome.crm.journal.person'))
                    ->options(fn (): array => OrgAccess::visibleUsers($viewer)->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('event')->label(__('gshome.crm.journal.event'))->options(self::eventOptions()),
                Filter::make('period')->label(__('gshome.crm.journal.period'))
                    ->schema([
                        DatePicker::make('from')->label(__('gshome.crm.journal.from')),
                        DatePicker::make('until')->label(__('gshome.crm.journal.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $d): Builder => $q->where('created_at', '>=', $d.' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $q, string $d): Builder => $q->where('created_at', '<=', $d.' 23:59:59'))),
                Filter::make('listing_id')->label(__('gshome.crm.journal.listing'))
                    ->schema([TextInput::make('listing_id')->label(__('gshome.crm.journal.listing'))->numeric()->minValue(1)])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['listing_id'] ?? null)
                        ? JournalQuery::forListing($query, (int) $data['listing_id'])
                        : $query),
            ]);
    }
}
