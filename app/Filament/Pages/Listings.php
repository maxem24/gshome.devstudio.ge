<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Models\Phone;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateStaleCursor;
use App\RealEstate\ListingQuery;
use App\RealEstate\ListingRows;
use App\RealEstate\Pagination\ApiCursorPaginator;
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
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * Объекты — живые запросы к API Real Estate (решение 2026-10-10). Направление —
 * только из прав смотрящего (ListingQuery), метки — ListingRows.
 */
class Listings extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.listings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $navigationLabel = 'Объекты';

    protected static ?string $title = 'Объекты';

    protected static ?int $navigationSort = -1;

    #[Url]
    public string $display = 'list';

    /** Фильтры и поиск живут в адресе: «назад» из карточки возвращает к тем же результатам. */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'search')]
    public $tableSearch = '';

    /** Вид, для которого собрана текущая таблица (см. render) */
    private ?string $tableDisplay = null;

    /**
     * Фильтры из адреса приходят от кого угодно: формы Filament падают на массиве там, где ждут
     * строку. Оставляем форму «группа → скаляры» (и список строк в `values`), остальное выбрасываем.
     */
    public function mount(): void
    {
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

        $this->restoreCursorFromUrl();
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

    /**
     * «Назад» из карточки: страница списка — курсор API в ?page=. Числовой резолвер Livewire
     * принимает только целые и заменяет курсор на 1, поэтому после его инициализации (она же
     * подключает синхронизацию с адресом) кладём курсор в paginators сами.
     */
    private function restoreCursorFromUrl(): void
    {
        $name = 'page'; // имя без идентификатора таблицы; таблица в mount() ещё не собрана
        $cursor = request()->query($name);
        Paginator::resolveCurrentPage($name);

        if (is_string($cursor) && $cursor !== '' && strlen($cursor) <= 2000 && ! ctype_digit($cursor)) {
            $this->paginators[$name] = $cursor;
        }
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
            ->records(fn (array $filters, ?string $search, int|string $page): ApiCursorPaginator => $this->fetch($filters, $search, $page))
            ->paginationMode(PaginationMode::Cursor)
            ->searchable()
            ->paginated([ListingQuery::PER_PAGE])
            ->recordUrl(fn (array $record): string => ListingView::getUrl(['listing' => $record['id']]))
            ->columns($this->display === 'grid' ? $this->gridColumns() : $this->listColumns())
            ->contentGrid($this->display === 'grid' ? ['md' => 2, 'xl' => 3] : null)
            ->filters($this->filters(), layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->emptyStateHeading('Объявлений не найдено')
            ->emptyStateDescription('Измените фильтры или направление.');
    }

    /** @param  array<string, mixed>  $filters */
    protected function fetch(array $filters, ?string $search, int|string $page): ApiCursorPaginator
    {
        $options = ['path' => request()->url(), 'cursorName' => $this->getTablePaginationPageName()];
        $cursor = is_string($page) && ! ctype_digit($page) ? $page : null;

        try {
            $params = ListingQuery::params($this->viewer(), $filters, $search);
            if ($params === null) {
                return ApiCursorPaginator::emptyPage(ListingQuery::PER_PAGE, $options);
            }
            try {
                $result = app(RealEstateClient::class)->listings($params, $cursor);
            } catch (RealEstateStaleCursor $e) {
                // Курсор от другой сортировки или старой закладки: молча начинаем с первой страницы.
                if ($cursor === null) {
                    throw $e;
                }
                $cursor = null;
                $this->resetPage($this->getTablePaginationPageName());
                $result = app(RealEstateClient::class)->listings($params, null);
            }
        } catch (RealEstateException $e) {
            Notification::make()->danger()->title($e->userMessage())->send();

            return ApiCursorPaginator::emptyPage(ListingQuery::PER_PAGE, $options);
        }

        return new ApiCursorPaginator(
            ListingRows::build($this->viewer(), $result->listings),
            ListingQuery::PER_PAGE,
            $cursor,
            $result->nextCursor,
            $result->prevCursor,
            $options,
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('showGrid')->label('Сетка')->icon(Heroicon::OutlinedSquares2x2)->color('gray')
                ->visible(fn (): bool => $this->display !== 'grid')
                ->action(fn () => $this->display = 'grid'),
            Action::make('showList')->label('Список')->icon(Heroicon::OutlinedListBullet)->color('gray')
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
                TextColumn::make('ownership_label')->badge()->color(fn (array $record): string => $record['ownership_color'])
                    ->description(fn (array $record): ?string => collect([$record['ownership_company'], $record['ownership_name']])->filter()->implode(' · ') ?: null),
                TextColumn::make('group_label')->color('success')->size('sm')->wrap(),
            ])->space(2),
        ];
    }

    /** @return array<int, TextColumn> */
    private function listColumns(): array
    {
        return [
            TextColumn::make('title')->label('Объект')->description(fn (array $record): ?string => $record['address'])->wrap(),
            TextColumn::make('price')->label('Цена'),
            TextColumn::make('direction')->label('Направление')->badge(),
            TextColumn::make('facts')->label('Параметры')->wrap(),
            TextColumn::make('source')->label('Источник'),
            TextColumn::make('created_at')->label('Появилось'),
            TextColumn::make('category')->label('Кто добавил'),
            TextColumn::make('ownership_label')->label('Принадлежность')->badge()
                ->color(fn (array $record): string => $record['ownership_color'])
                ->description(fn (array $record): ?string => collect([$record['ownership_company'], $record['ownership_name'], $record['group_label']])->filter()->implode(' · ') ?: null),
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
            SelectFilter::make('direction')->label('Направление')
                ->options(collect($directions)->mapWithKeys(fn (Direction $d): array => [$d->value => $d->getLabel()])->all())
                ->placeholder('Все')
                ->visible(count($directions) > 1),
            Filter::make('price')->schema([
                TextInput::make('min')->label('Цена от, $')->numeric(),
                TextInput::make('max')->label('Цена до, $')->numeric(),
            ])->columns(2),
            Filter::make('location')->schema([
                Select::make('city_id')->label('Город')->options(fn (): array => $dictionary('cities'))->searchable()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('district_id', null)),
                Select::make('district_id')->label('Район')
                    ->options(fn (Get $get): array => is_scalar($get('city_id')) && ctype_digit((string) $get('city_id')) && (int) $get('city_id') > 0 ? $dictionary('districts', ['city_id' => (int) $get('city_id')]) : [])
                    ->searchable(),
            ])->columns(2),
            Filter::make('area')->schema([
                TextInput::make('min')->label('Площадь от, м²')->numeric(),
                TextInput::make('max')->label('Площадь до, м²')->numeric(),
            ])->columns(2),
            SelectFilter::make('rooms')->label('Комнаты')->multiple()
                ->options(['1' => '1', '2' => '2', '3' => '3', '4' => '4', '5+' => '5+']),
            SelectFilter::make('property_type')->label('Тип недвижимости')->options(fn (): array => $dictionary('property-types')),
            SelectFilter::make('source')->label('Источник')->options(['myhome_ge' => 'myhome.ge', 'ss_ge' => 'ss.ge']),
            Filter::make('owners_only')->label('Только собственники')->toggle(),
            Filter::make('created')->schema([
                DatePicker::make('from')->label('Появилось с')->live()->maxDate(fn (Get $get): ?string => $get('until') ?: null),
                DatePicker::make('until')->label('Появилось по')->live()->minDate(fn (Get $get): ?string => $get('from') ?: null),
            ])->columns(2),
            SelectFilter::make('sort')->label('Сортировка')->options(ListingQuery::SORTS)->placeholder('Сначала новые')
                ->modifyFormFieldUsing(fn (Select $field): Select => $field->helperText('При сортировке по цене или площади объявления без них не показываются')),
            SelectFilter::make('ownership')->label('Принадлежность')
                ->options(['ours' => 'Наши (видимые мне)', 'others' => 'Другие компании'])
                ->visible($client->phoneFiltersEnabled()),
            SelectFilter::make('employee')->label('Сотрудник')
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
