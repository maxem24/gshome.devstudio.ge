<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Enums\Direction;
use App\Models\Phone;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\ListingQuery;
use App\RealEstate\ListingRows;
use App\RealEstate\Pagination\ApiCursorPaginator;
use App\RealEstate\RealEstateClient;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
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

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (array $filters, ?string $search, int|string $page): ApiCursorPaginator => $this->fetch($filters, $search, $page))
            ->paginationMode(PaginationMode::Cursor)
            ->paginated([ListingQuery::PER_PAGE])
            ->columns($this->listColumns())
            ->filters($this->filters(), layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->emptyStateHeading('Объявлений не найдено')
            ->emptyStateDescription('Измените фильтры или направление.');
    }

    /** @param  array<string, mixed>  $filters */
    public function fetch(array $filters, ?string $search, int|string $page): ApiCursorPaginator
    {
        $options = ['path' => request()->url(), 'cursorName' => $this->getTablePaginationPageName()];
        $cursor = is_string($page) && ! ctype_digit($page) ? $page : null;

        try {
            $params = ListingQuery::params($this->viewer(), $filters, $search);
            if ($params === null) {
                return ApiCursorPaginator::emptyPage(ListingQuery::PER_PAGE, $options);
            }
            $result = app(RealEstateClient::class)->listings($params, $cursor);
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

    /** @return array<int, TextColumn> */
    private function listColumns(): array
    {
        return [
            TextColumn::make('title')->label('Объект')->description(fn (array $record): ?string => $record['address'])->wrap()->searchable(),
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
                Select::make('city_id')->label('Город')->options(fn (): array => $dictionary('cities'))->searchable()->live(),
                Select::make('district_id')->label('Район')
                    ->options(fn (Get $get): array => filled($get('city_id')) ? $dictionary('districts', ['city_id' => (int) $get('city_id')]) : [])
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
                DatePicker::make('from')->label('Появилось с'),
                DatePicker::make('until')->label('Появилось по'),
            ])->columns(2),
            SelectFilter::make('sort')->label('Сортировка')->options(ListingQuery::SORTS)->placeholder('Сначала новые'),
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
