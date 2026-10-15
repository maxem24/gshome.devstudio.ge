<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Enums\ListingScope;
use App\Enums\Role;
use App\Enums\SellerCategory;
use App\Models\Phone;
use App\Models\SavedListing;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateStaleCursor;
use App\RealEstate\Listing;
use App\RealEstate\ListingPage;
use App\RealEstate\ListingQuery;
use App\RealEstate\ListingRows;
use App\RealEstate\RealEstateClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Объекты — живые запросы к API Real Estate (решение 2026-10-10). Направление —
 * только из прав смотрящего (ListingQuery), метки — ListingRows.
 * Страницы курсора дописываются вниз, а не заменяют список (решение 2026-10-14).
 */
class Listings extends Page implements HasTable
{
    use InteractsWithTable;

    /** Сколько строк держим в состоянии компонента: дальше прокрутка останавливается. */
    private const MAX_LOADED = 200;

    /** Сколько страниц API смотреть за один заход «Мои», пока среди них нет копий этого агента. */
    private const MAX_MINE_PAGES = 8;

    protected string $view = 'filament.pages.listings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?int $navigationSort = -1;

    public static function getNavigationLabel(): string
    {
        return __('gshome.nav.listings');
    }

    public function getTitle(): string
    {
        return __('gshome.listings.title');
    }

    #[Url]
    public string $display = 'grid';

    /** Пять кнопок над свёрнутым фильтром. Не направление сделки. */
    #[Url]
    public string $scope = 'all';

    /** Ключ сессии Filament для значений фильтров (`getTableFiltersSessionKey`, без арендатора). */
    public static function filtersSessionKey(): string
    {
        return 'tables.'.md5(self::class).'_filters';
    }

    public static function forgetRememberedFilters(): void
    {
        session()->forget(self::filtersSessionKey());
    }

    /** Фильтры и поиск живут в адресе: «назад» из карточки возвращает к тем же результатам. */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'search')]
    public $tableSearch = '';

    /**
     * Уже показанные строки, ключ — id объявления. Курсор следующей страницы API.
     * Из браузера их не меняют: догрузка и сохранение читают только это состояние.
     *
     * @var array<int|string, array<string, mixed>>
     */
    #[Locked]
    public array $loadedRows = [];

    #[Locked]
    public ?string $nextCursor = null;

    #[Locked]
    public bool $listingsLoaded = false;

    #[Locked]
    public bool $noMoreListings = false;

    #[Locked]
    public ?string $loadedFingerprint = null;

    /** @var array<string, string>|null */
    #[Locked]
    public ?array $loadedParams = null;

    /** Вид, для которого собрана текущая таблица (см. render) */
    private ?string $tableDisplay = null;

    /** @var list<int>|null */
    private ?array $savedIds = null;

    /**
     * Фильтры из адреса приходят от кого угодно: формы Filament падают на массиве там, где ждут
     * строку. Оставляем форму «группа → скаляры» (и список строк в `values`), остальное выбрасываем.
     */
    public function mount(): void
    {
        if (ListingScope::tryFrom($this->scope) === null) {
            $this->scope = ListingScope::All->value;
        }

        $clean = [];
        foreach ($this->tableFilters ?? [] as $name => $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ($group as $key => $value) {
                if ($key === 'values' && is_array($value)) {
                    $clean[$name][$key] = array_values(array_filter($value, 'is_string'));
                } elseif (is_scalar($value) || $value === null) {
                    $clean[$name][$key] = $value;
                }
            }
        }
        $this->tableFilters = $clean === [] ? null : $clean;

        // ?search[]=x кладётся в нетипизированное свойство как есть — и роняет getTableSearch().
        if (! is_scalar($this->tableSearch)) {
            $this->tableSearch = '';
        }
    }

    /** `$wire.set('tableSearch', [...])` кладёт массив в снапшот, а booted() трейта делает strval() — чистим до него. */
    public function hydrate(): void
    {
        if (! is_scalar($this->tableSearch)) {
            $this->tableSearch = '';
        }
    }

    /** Нетипизированное свойство трейта: запрос с массивом (в т.ч. $wire.set) не должен давать 500. */
    public function getTableSearch(): ?string
    {
        return is_scalar($this->tableSearch) && filled($this->tableSearch) ? Str::trim((string) $this->tableSearch) : null;
    }

    public function setScope(string $scope): void
    {
        abort_unless(ListingScope::tryFrom($scope) !== null, 404);
        $this->scope = $scope;
    }

    /** Компания, которую администратор представляет. У остальных ролей строки нет. */
    public function representedCompany(): ?string
    {
        $user = $this->viewer();

        return $user->role === Role::CompanyOwner ? $user->company?->name : null;
    }

    /** Район принадлежит городу: другой город — район сбрасывается (и в форме, и при правке состояния напрямую). */
    public function updatedTableFiltersLocationCityId(): void
    {
        data_set($this->tableFilters, 'location.district_id', null);
    }

    public function table(Table $table): Table
    {
        $this->tableDisplay = $this->display;

        return $table
            ->records(fn (array $filters, ?string $search): array => $this->listingRows($filters, $search))
            ->searchable()
            ->paginated(false)
            ->recordUrl(fn (array $record): string => ListingView::getUrl(['listing' => $record['id']]))
            ->recordActions([
                Action::make('saveForLater')
                    ->label(fn (array $record): string => $this->isSaved((int) $record['id'])
                        ? __('gshome.listings.saved')
                        : __('gshome.listings.save_for_later'))
                    ->icon(fn (array $record): Heroicon => $this->isSaved((int) $record['id'])
                        ? Heroicon::Bookmark
                        : Heroicon::OutlinedBookmark)
                    ->color('gray')
                    ->action(function (array $record): void {
                        $id = (int) $record['id'];
                        if ($this->isSaved($id)) {
                            $this->forgetListing($id);
                        } else {
                            $this->saveListing($id);
                        }
                    }),
            ], position: RecordActionsPosition::AfterContent)
            ->columns($this->display === 'grid' ? $this->gridColumns() : $this->listColumns())
            ->contentGrid($this->display === 'grid' ? ['md' => 2, 'xl' => 3] : null)
            ->filters($this->filters(), layout: FiltersLayout::AboveContentCollapsible)
            ->persistFiltersInSession()
            ->filtersFormColumns(4)
            ->emptyStateHeading(__('gshome.listings.empty_heading'))
            ->emptyStateDescription(__('gshome.listings.empty_description'));
    }

    /**
     * Первая страница запрашивается один раз на сочетание фильтров и поиска.
     * Смена фильтра или поиска начинает список заново, без старого курсора.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int|string, array<string, mixed>>
     */
    private function listingRows(array $filters, ?string $search): array
    {
        $params = $this->paramsFor($filters, $search);
        if (! $this->listingsLoaded) {
            $this->append($params, null);
        }

        return $this->loadedRows;
    }

    /** Догрузить следующую страницу курсора API и дописать её к уже показанным строкам. */
    public function loadMoreListings(): void
    {
        if ($this->noMoreListings || $this->nextCursor === null || $this->loadedParams === null) {
            return;
        }

        $this->append($this->loadedParams, $this->nextCursor);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>|null
     */
    private function paramsFor(array $filters, ?string $search): ?array
    {
        $scope = ListingScope::tryFrom($this->scope) ?? ListingScope::All;
        $params = ListingQuery::params($this->viewer(), $filters, $search, $scope);
        $fingerprint = md5($scope->value.'|'.(string) json_encode($params));
        if ($this->loadedFingerprint !== $fingerprint) {
            $this->loadedRows = [];
            $this->nextCursor = null;
            $this->listingsLoaded = false;
            $this->noMoreListings = false;
            $this->loadedFingerprint = $fingerprint;
            $this->loadedParams = $params;
        }

        return $params;
    }

    /** @param  array<string, string>|null  $params */
    private function append(?array $params, ?string $cursor): void
    {
        if ($params === null) {
            $this->listingsLoaded = true;
            $this->nextCursor = null;
            $this->noMoreListings = true;

            return;
        }

        $scope = ListingScope::tryFrom($this->scope) ?? ListingScope::All;
        $added = 0;
        $guard = 0;
        do {
            $result = $this->fetchPage($params, $cursor);
            if ($result === null) {
                return;
            }

            $listings = $result->listings;
            if ($scope === ListingScope::Mine) {
                $allowed = array_flip(array_values(array_filter(explode(',', (string) ($params['phones'] ?? '')), fn (string $number): bool => $number !== '')));
                $listings = array_values(array_filter(
                    $listings,
                    fn (Listing $listing): bool => is_string($listing->contactPhoneE164) && isset($allowed[$listing->contactPhoneE164]),
                ));
            }
            foreach (ListingRows::build($this->viewer(), $this->enrichClusters($listings)) as $id => $row) {
                if (count($this->loadedRows) >= self::MAX_LOADED) {
                    break;
                }
                $this->loadedRows[$id] = $row;
                $added++;
            }
            $this->listingsLoaded = true;
            $this->nextCursor = count($this->loadedRows) >= self::MAX_LOADED ? null : $result->nextCursor;
            $this->noMoreListings = $this->nextCursor === null;
            $cursor = $this->nextCursor;
            $guard++;
        } while ($scope === ListingScope::Mine && $added === 0 && $cursor !== null && $guard < self::MAX_MINE_PAGES);

        if ($scope === ListingScope::Mine && $added === 0 && $guard >= self::MAX_MINE_PAGES) {
            $this->nextCursor = null;
            $this->noMoreListings = true;
        }
    }

    /** @param  array<string, string>  $params */
    private function fetchPage(array $params, ?string $cursor): ?ListingPage
    {
        try {
            try {
                return app(RealEstateClient::class)->listings($params, $cursor);
            } catch (RealEstateStaleCursor $e) {
                if ($cursor === null) {
                    throw $e;
                }
                $this->loadedRows = [];
                $this->nextCursor = null;
                $this->listingsLoaded = false;
                $this->append($params, null);

                return null;
            }
        } catch (RealEstateException $e) {
            Notification::make()->danger()->title($e->userMessage())->send();
            $this->listingsLoaded = true;
            $this->nextCursor = null;
            $this->noMoreListings = true;

            return null;
        }
    }

    /**
     * В списке у кластера есть телефоны, но нет типа продавца соседей.
     * Карточку читаем, только если наша сторона уже в группе, а видимое объявление — не собственник.
     *
     * @param  list<Listing>  $listings
     * @return list<Listing>
     */
    private function enrichClusters(array $listings): array
    {
        $ours = array_flip(OrgAccess::identifiablePhones($this->viewer()));
        $fetched = 0;
        $out = [];
        foreach ($listings as $listing) {
            $touchesUs = false;
            foreach ([$listing->contactPhoneE164, ...$listing->clusterPhonesE164] as $number) {
                if (is_string($number) && isset($ours[$number])) {
                    $touchesUs = true;
                    break;
                }
            }
            if ($fetched >= 5 || $listing->clusterListings !== [] || $listing->clusterListingsCount < 2 || $listing->sellerCategory === SellerCategory::Owner || ! $touchesUs) {
                $out[] = $listing;

                continue;
            }
            $fetched++;
            try {
                $out[] = app(RealEstateClient::class)->listing($listing->id) ?? $listing;
            } catch (RealEstateException) {
                $out[] = $listing;
            }
        }

        return $out;
    }

    public function saveListing(int $id): void
    {
        abort_unless($id > 0 && isset($this->loadedRows[$id]), 404);
        SavedListing::query()->firstOrCreate([
            'user_id' => $this->viewer()->id,
            'listing_id' => $id,
        ]);
        $this->savedIds = null;
    }

    public function forgetListing(int $id): void
    {
        abort_unless($id > 0 && isset($this->loadedRows[$id]), 404);
        SavedListing::query()->where('user_id', $this->viewer()->id)->where('listing_id', $id)->delete();
        $this->savedIds = null;
    }

    private function isSaved(int $id): bool
    {
        $this->savedIds ??= SavedListing::query()
            ->where('user_id', $this->viewer()->id)
            ->pluck('listing_id')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        return in_array($id, $this->savedIds, true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('showGrid')->label(__('gshome.listings.grid'))->icon(Heroicon::OutlinedSquares2x2)->color('gray')
                ->visible(fn (): bool => $this->display !== 'grid')
                ->action(fn () => $this->display = 'grid'),
            Action::make('showList')->label(__('gshome.listings.list'))->icon(Heroicon::OutlinedListBullet)->color('gray')
                ->visible(fn (): bool => $this->display === 'grid')
                ->action(fn () => $this->display = 'list'),
        ];
    }

    /**
     * Колонки задаются при сборке таблицы, а вид из адреса (#[Url]) и кнопки меняются
     * уже после неё — пересобираем таблицу до рендера, если она собрана для другого вида.
     */
    public function render(): View
    {
        if ($this->tableDisplay !== $this->display) {
            $this->table = $this->table($this->makeTable());
        }

        return parent::render();
    }

    /** @return array<int, Stack> плитка сетки (ТЗ 4.2) */
    private function gridColumns(): array
    {
        return [
            Stack::make([
                TextColumn::make('title')->weight(FontWeight::Bold)->wrap(),
                ImageColumn::make('image')->label('')->imageHeight(180)->extraImgAttributes(['class' => 'w-full object-cover rounded-lg'])
                    ->defaultImageUrl(null),
                TextColumn::make('price')->weight(FontWeight::Bold)->size('lg'),
                TextColumn::make('direction')->badge(),
                TextColumn::make('address')->color('gray')->wrap(),
                TextColumn::make('facts')->wrap(),
                TextColumn::make('ownership_label')->badge()->color(fn (array $record): string => $record['ownership_color']),
                TextColumn::make('ownership_notes')->html()->placeholder(''),
            ])->space(2),
        ];
    }

    /** @return array<int, TextColumn> */
    private function listColumns(): array
    {
        return [
            TextColumn::make('title')->label(__('gshome.listings.object'))->description(fn (array $record): ?string => $record['address'])->wrap(),
            TextColumn::make('price')->label(__('gshome.listings.price')),
            TextColumn::make('direction')->label(__('gshome.listings.direction'))->badge(),
            TextColumn::make('facts')->label(__('gshome.listings.facts'))->wrap(),
            TextColumn::make('source')->label(__('gshome.listings.source')),
            TextColumn::make('created_at')->label(__('gshome.listings.created')),
            TextColumn::make('category')->label(__('gshome.listings.category')),
            TextColumn::make('ownership_label')->label(__('gshome.listings.ownership'))->badge()
                ->color(fn (array $record): string => $record['ownership_color']),
            TextColumn::make('ownership_notes')->label('')->html()->placeholder(''),
        ];
    }

    /** @return array<int, Filter|SelectFilter> */
    private function filters(): array
    {
        $client = app(RealEstateClient::class);
        $directions = OrgAccess::directions($this->viewer());
        $dictionary = fn (string $name, array $params = []): array => rescue(
            fn (): array => $client->dictionary($name, $params)->mapWithKeys(fn (array $r): array => [$r['id'] => RealEstateClient::title($r)])->all(),
            [],
            report: false,
        );

        return [
            SelectFilter::make('direction')->label(__('gshome.listings.direction'))
                ->options(collect($directions)->mapWithKeys(fn (Direction $d): array => [$d->value => $d->getLabel()])->all())
                ->placeholder(__('gshome.listings.all'))
                ->visible(count($directions) > 1),
            Filter::make('price')->schema([
                TextInput::make('min')->label(__('gshome.listings.price_min'))->numeric(),
                TextInput::make('max')->label(__('gshome.listings.price_max'))->numeric(),
            ])->columns(2),
            Filter::make('location')->schema([
                Select::make('city_id')->label(__('gshome.listings.city'))->options(fn (): array => $dictionary('cities'))->searchable()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('district_id', null)),
                Select::make('district_id')->label(__('gshome.listings.district'))
                    ->options(fn (Get $get): array => is_scalar($get('city_id')) && ctype_digit((string) $get('city_id')) && (int) $get('city_id') > 0 ? $dictionary('districts', ['city_id' => (int) $get('city_id')]) : [])
                    ->searchable(),
            ])->columns(2),
            Filter::make('area')->schema([
                TextInput::make('min')->label(__('gshome.listings.area_min'))->numeric(),
                TextInput::make('max')->label(__('gshome.listings.area_max'))->numeric(),
            ])->columns(2),
            SelectFilter::make('rooms')->label(__('gshome.listings.rooms'))->multiple()
                ->options(['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5+' => '5+']),
            SelectFilter::make('property_type')->label(__('gshome.listings.property_type'))->options(fn (): array => $dictionary('property-types')),
            SelectFilter::make('source')->label(__('gshome.listings.source'))->options(['myhome_ge' => 'myhome.ge', 'ss_ge' => 'ss.ge']),
            Filter::make('owners_only')->label(__('gshome.listings.owners_only'))->toggle(),
            Filter::make('created')->schema([
                DatePicker::make('from')->label(__('gshome.listings.created_from'))->live()->maxDate(fn (Get $get): ?string => $get('until') ?: null),
                DatePicker::make('until')->label(__('gshome.listings.created_until'))->live()->minDate(fn (Get $get): ?string => $get('from') ?: null),
            ])->columns(2),
            SelectFilter::make('sort')->label(__('gshome.listings.sort'))->options(ListingQuery::sortOptions())->placeholder(__('gshome.listings.sort_new'))
                ->modifyFormFieldUsing(fn (Select $field): Select => $field->helperText(__('gshome.listings.sort_hint'))),
            SelectFilter::make('ownership')->label(__('gshome.listings.ownership'))
                ->options(['ours' => __('gshome.listings.ours'), 'others' => __('gshome.listings.others')])
                ->visible($client->phoneFiltersEnabled()),
            SelectFilter::make('employee')->label(__('gshome.listings.employee'))
                ->options(fn (): array => $this->employeeOptions())
                ->searchable()
                ->visible($client->phoneFiltersEnabled()),
        ];
    }

    /** @return array<int, string> люди с номерами в компаниях, которых смотрящий видит по имени */
    private function employeeOptions(): array
    {
        return User::query()
            ->whereIn('id', Phone::query()->whereNotNull('user_id')->whereIn('company_id', OrgAccess::identifiableCompanyIds($this->viewer()))->select('user_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
