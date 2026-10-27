<?php

namespace App\Filament\Pages;

use App\Crm\Exceptions\CrmForbidden;
use App\Enums\Direction;
use App\Enums\Role;
use App\Models\ListingSearch;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\Search\SearchStart;
use App\Search\SiteSearches;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Мои поиски по сайтам: свои и людей, которых видит человек; новые сверху. */
class MySearches extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.my-searches';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static ?string $slug = 'my-searches';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('gshome.crm.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('gshome.search.mine.nav');
    }

    public function getTitle(): string
    {
        return __('gshome.search.mine.title');
    }

    public function table(Table $table): Table
    {
        $viewer = $this->viewer();

        return $table
            ->query(fn () => ListingSearch::query()->visibleTo($this->viewer())->with('user')->latest('id'))
            ->poll(fn (): ?string => ListingSearch::query()->visibleTo($this->viewer())->whereIn('status', ListingSearch::RUNNING)->exists() ? '10s' : null)
            ->recordUrl(fn (ListingSearch $record): string => SearchResult::getUrl(['search' => $record->getKey()]))
            ->columns([
                TextColumn::make('user.name')->label(__('gshome.search.mine.person'))
                    ->visible($viewer->role !== Role::Agent),
                TextColumn::make('filters')->label(__('gshome.search.mine.filters'))
                    ->state(fn (ListingSearch $record): string => self::summary($record->filters))->wrap(),
                TextColumn::make('status')->label(__('gshome.search.mine.status'))->badge()
                    ->state(fn (ListingSearch $record): string => __("gshome.search.status.{$record->status}"))
                    ->color(fn (ListingSearch $record): string => match (true) {
                        $record->isRunning() => 'info',
                        $record->status === 'done' && ! $record->isPartial() => 'success',
                        $record->status === 'done' => 'warning',
                        default => 'danger',
                    })
                    ->description(fn (ListingSearch $record): ?string => $record->message)->wrap(),
                TextColumn::make('sites')->label(__('gshome.search.mine.sites'))
                    ->state(fn (ListingSearch $record): array => self::siteLines($record))->listWithLineBreaks()->wrap(),
                TextColumn::make('found')->label(__('gshome.search.mine.found'))
                    ->state(fn (ListingSearch $record): int => $record->shown())->numeric()
                    ->description(fn (ListingSearch $record): ?string => $record->status === 'done' ? $record->foundOnSitesNote() : null),
                TextColumn::make('started_at')->label(__('gshome.search.mine.started'))
                    ->dateTime('d.m.Y H:i', (string) config('crm.timezone')),
            ])
            ->recordActions([
                Action::make('again')
                    ->label(__('gshome.search.mine.again'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->button()
                    // Повторить может только автор: чужие поиски руководитель видит, но не запускает.
                    ->visible(fn (ListingSearch $record): bool => $record->user_id === $this->viewer()->getKey())
                    ->action(fn (ListingSearch $record) => $this->searchAgain((int) $record->getKey())),
            ])
            ->emptyStateHeading(__('gshome.search.mine.empty'))
            ->paginated([10, 25, 50]);
    }

    public function searchAgain(int $id): void
    {
        $viewer = $this->viewer();
        $search = ListingSearch::query()->visibleTo($viewer)->findOrFail($id);
        abort_unless($search->user_id === $viewer->getKey(), 403);

        try {
            $start = app(SiteSearches::class)->start($viewer, $search->filters);
        } catch (CrmForbidden) {
            abort(403);
        } catch (RealEstateRateLimited) {
            Notification::make()->danger()->title(__('gshome.search.rate_limited'))->send();

            return;
        } catch (RealEstateException $e) {
            Notification::make()->danger()->title($e->userMessage())->send();

            return;
        }

        $this->notifyStart($start);
    }

    /** Что сказать после старта: новый поиск, такой же уже идёт или есть свежий готовый. */
    public static function notifyStart(SearchStart $start): void
    {
        $notification = Notification::make()->success();
        match ($start->kind) {
            SearchStart::RUNNING => $notification->title(__('gshome.search.started_running')),
            SearchStart::REUSED => $notification->title(__('gshome.search.reused'))->actions([
                Action::make('open')->label(__('gshome.search.open'))->url(SearchResult::getUrl(['search' => $start->search->getKey()])),
            ]),
            default => $notification->title(__('gshome.search.started')),
        };
        $notification->send();
    }

    /**
     * Фильтры коротко: сделка, цена, комнаты, площадь, число районов.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function summary(array $filters): string
    {
        $parts = [];
        if (filled($filters['deal'] ?? null) && ($d = Direction::tryFrom((string) $filters['deal'])) !== null) {
            $parts[] = $d->getLabel();
        }
        $range = fn (string $key, string $unit): ?string => match (true) {
            filled($filters["{$key}_min"] ?? null) && filled($filters["{$key}_max"] ?? null) => "{$filters["{$key}_min"]}–{$filters["{$key}_max"]} {$unit}",
            filled($filters["{$key}_min"] ?? null) => "{$filters["{$key}_min"]}+ {$unit}",
            filled($filters["{$key}_max"] ?? null) => "≤ {$filters["{$key}_max"]} {$unit}",
            default => null,
        };
        $parts[] = $range('price_usd', '$');
        $parts[] = $range('area', 'm²');
        if (filled($filters['rooms'] ?? null)) {
            $parts[] = implode(', ', (array) $filters['rooms']).' '.__('gshome.search.rooms');
        }
        if (filled($filters['district_id'] ?? null)) {
            $parts[] = count((array) $filters['district_id']).' '.__('gshome.search.districts');
        }
        $parts = array_values(array_filter($parts));

        return $parts === [] ? __('gshome.search.mine.any') : implode(' · ', $parts);
    }

    /** @return list<string> */
    public static function siteLines(ListingSearch $search): array
    {
        $lines = [];
        foreach ($search->sites ?? [] as $key => $site) {
            $status = (string) ($site['status'] ?? '');
            $label = __("gshome.search.site_status.{$status}");
            $lines[] = str_replace('_ge', '.ge', (string) $key).': '.($label === "gshome.search.site_status.{$status}" ? $status : $label)
                .' ('.(int) ($site['found'] ?? 0).')'
                .(filled($site['message'] ?? null) ? ' — '.$site['message'] : '');
        }

        return $lines;
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
