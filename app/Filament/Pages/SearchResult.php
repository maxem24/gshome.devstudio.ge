<?php

namespace App\Filament\Pages;

use App\Models\ListingSearch;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateStaleCursor;
use App\RealEstate\ListingQuery;
use App\RealEstate\ListingRows;
use App\RealEstate\RealEstateClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Результат поиска по сайтам: найденные id страницами по 24, в выбранном порядке; чужое направление и снятое скрыто.
 *
 * @property-read ListingSearch $search
 */
class SearchResult extends Page implements HasTable
{
    use InteractsWithTable;

    /** Объявлений на странице: делится на 2 и 3 колонки сетки. */
    public const PER_PAGE = 24;

    protected string $view = 'filament.pages.search-result';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'site-search';

    #[Locked]
    public int $searchId = 0;

    /** Порядок — ключ ListingQuery::sortOptions(); живёт в адресе, «назад» из карточки его не теряет. */
    #[Url]
    public string $sort = 'new';

    /** @var array<int|string, array<string, mixed>> */
    #[Locked]
    public array $rows = [];

    /** Сколько найденных объявлений уже сняли с сайтов (meta.missing_ids). */
    #[Locked]
    public int $hidden = 0;

    #[Locked]
    public bool $incomplete = false;

    /**
     * Номер страницы (с 1) и курсор API — в адресе, как и порядок: «назад» из карточки возвращает на ту же страницу.
     * Подменённый курсор безопасен: набор id и направление запроса задаёт сервер (fetchRows), протухший — с первой страницы.
     */
    // Не «page»: этот ключ адреса занимает WithPagination, который таблица Filament подключает сама.
    #[Url(as: 'p')]
    public int $page = 1;

    #[Url(as: 'c')]
    public ?string $cursor = null;

    /** Курсоры соседних страниц — из ответа API, не из адреса. */
    #[Locked]
    public ?string $nextCursor = null;

    #[Locked]
    public ?string $prevCursor = null;

    /** Для каких данных поиска, порядка и страницы строки прочитаны: сменились — читаем заново. */
    #[Locked]
    public ?string $loadedFor = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/searches/{search}';
    }

    public function getTitle(): string
    {
        return __('gshome.search.result_title', ['id' => $this->searchId]);
    }

    public function mount(int|string $search): void
    {
        abort_unless(ctype_digit((string) $search) && (int) $search > 0, 404);
        $this->searchId = (int) $search;
        $this->search();
        $this->normalizePage();
    }

    /** Страницу и курсор можно поменять и из браузера ($wire.set) — проверяем так же, как адрес. */
    public function updated(string $name): void
    {
        if (in_array($name, ['page', 'cursor'], true)) {
            $this->normalizePage();
        }
    }

    /** Страница из адреса без курсора (или курсор на «первой») — это первая страница. */
    private function normalizePage(): void
    {
        if (! is_string($this->cursor) || $this->cursor === '' || strlen($this->cursor) > 512) {
            $this->cursor = null;
        }
        if ($this->cursor === null || $this->page < 2) {
            $this->page = 1;
            $this->cursor = null;
        }
    }

    /** Запись перечитывается при каждом обращении (в том числе wire:poll): права проверяются заново. */
    #[Computed]
    public function search(): ListingSearch
    {
        return ListingSearch::query()->visibleTo($this->viewer())->with('user')->findOrFail($this->searchId);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->loadRows())
            ->paginated(false)
            ->recordUrl(fn (array $record): string => ListingView::getUrl(['listing' => $record['id'], 'search' => $this->searchId]))
            ->columns([
                // Stack: только раскладка из колонок-стопок даёт сетку карточек, одиночная колонка рисует таблицу.
                Stack::make([ViewColumn::make('card')->view('filament.listings.card')]),
            ])
            ->contentGrid(['md' => 2, 'lg' => 3, 'xl' => 4])
            ->emptyStateHeading(__('gshome.search.result_empty'));
    }

    /**
     * Строки текущей страницы; читаются один раз на сочетание данных поиска, порядка и страницы.
     * Шапка зовёт это до таблицы: счётчик «1–24 из 51» и стрелки должны знать страницу заранее.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function loadRows(): array
    {
        $search = $this->search;
        $key = implode(':', [$search->status, $search->found, count($search->listing_ids ?? []), $this->sort, $this->cursor]);
        if ($this->loadedFor !== $key) {
            $this->rows = $this->fetchRows($search);
            $this->loadedFor = $key;
        }

        return $this->rows;
    }

    /** @return array<string, string> */
    public function sortOptions(): array
    {
        return ListingQuery::sortOptions();
    }

    /** Новый порядок — с первой страницы: курсор API действует только с той же sort. */
    public function updatedSort(): void
    {
        if (ListingQuery::sortParam($this->sort) === null) {
            $this->sort = 'new';
        }
        $this->cursor = null;
        $this->page = 1;
    }

    /** Не nextPage(): так называется метод WithPagination, который таблица Filament подключает сама. */
    public function nextResultPage(): void
    {
        if ($this->nextCursor !== null) {
            $this->cursor = $this->nextCursor;
            $this->page++;
        }
    }

    public function previousResultPage(): void
    {
        if ($this->page > 1) {
            $this->cursor = $this->page === 2 ? null : $this->prevCursor;
            $this->page--;
        }
    }

    /** Сколько объявлений нашёл поиск (до 100 id) — для «1–24 из 51». */
    public function total(): int
    {
        return count($this->search->listing_ids ?? []);
    }

    /**
     * Одна страница — один запрос `GET /listings?ids=…` (раньше — карточка на каждый id, до 100 запросов).
     * Направление и порядок отбирает Real Estate; снятые приходят в meta.missing_ids.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(ListingSearch $search): array
    {
        $ids = array_slice(array_map('intval', $search->listing_ids ?? []), 0, 100);
        $this->incomplete = false;
        $this->nextCursor = null;
        $this->prevCursor = null;
        if ($ids === []) {
            $this->hidden = 0;

            return [];
        }

        $client = app(RealEstateClient::class);
        try {
            // Направление — у самого поиска: агент продаж мог искать аренду (02_Решения, 27.10). Доступ к поиску решает visibleTo.
            // Справочник тоже может не ответить: это «список неполный», а не 500.
            $params = array_filter([
                'ids' => implode(',', $ids),
                'per_page' => (string) self::PER_PAGE,
                'deal_type_id' => ListingQuery::dealTypeIdsFor($search->directions(), $client),
                'sort' => ListingQuery::sortParam($this->sort),
            ], fn (?string $v): bool => filled($v));
            try {
                $page = $client->listings($params, $this->cursor);
            } catch (RealEstateStaleCursor) {
                $this->cursor = null;
                $this->page = 1;
                $page = $client->listings($params, null);
            }
        } catch (RealEstateException $e) {
            $this->incomplete = true;
            $this->hidden = 0;
            Notification::make()->danger()->title($e->userMessage())->send();

            return [];
        }

        $this->nextCursor = $page->nextCursor;
        $this->prevCursor = $page->prevCursor;
        $this->hidden = count($page->missingIds);

        return ListingRows::build($this->viewer(), $page->listings);
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
