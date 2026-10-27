<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Models\ListingSearch;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Listing;
use App\RealEstate\ListingRows;
use App\RealEstate\RealEstateClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Результат поиска по сайтам: карточки найденных id, чужое направление и снятое скрыто.
 *
 * @property-read ListingSearch $search
 */
class SearchResult extends Page implements HasTable
{
    use InteractsWithTable;

    /** Не больше стольких карточек читаем за заход (API отдаёт до 100 id). */
    public const LIMIT = 100;

    protected string $view = 'filament.pages.search-result';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'site-search';

    #[Locked]
    public int $searchId = 0;

    /** @var array<int|string, array<string, mixed>> */
    #[Locked]
    public array $rows = [];

    /** Сколько id из результата не показано. */
    #[Locked]
    public int $hidden = 0;

    #[Locked]
    public bool $incomplete = false;

    /** Для каких данных поиска строки прочитаны: сменились — читаем заново (карточки берутся из кеша клиента). */
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
            ->records(function (): array {
                $search = $this->search;
                $key = $search->status.':'.$search->found.':'.count($search->listing_ids ?? []);
                if ($this->loadedFor !== $key) {
                    $this->rows = $this->fetchRows($search);
                    $this->loadedFor = $key;
                }

                return $this->rows;
            })
            ->paginated(false)
            ->recordUrl(fn (array $record): string => ListingView::getUrl(['listing' => $record['id']]))
            ->columns([
                Stack::make([
                    TextColumn::make('title')->weight(FontWeight::Bold)->wrap(),
                    ImageColumn::make('image')->label('')->imageHeight(180)->extraImgAttributes(['class' => 'w-full object-cover rounded-lg'])
                        ->defaultImageUrl(null),
                    TextColumn::make('price')->weight(FontWeight::Bold)->size('lg'),
                    TextColumn::make('direction')->badge(),
                    TextColumn::make('address')->color('gray')->wrap(),
                    TextColumn::make('facts')->wrap(),
                    TextColumn::make('partial_label')->state(fn (array $record): ?string => $record['partial'] ? __('gshome.search.card_partial') : null)
                        ->badge()->color('warning')->placeholder(''),
                    TextColumn::make('ownership_label')->badge()->color(fn (array $record): string => $record['ownership_color']),
                    TextColumn::make('ownership_notes')->html()->placeholder(''),
                ])->space(2),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->emptyStateHeading(__('gshome.search.result_empty'));
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchRows(ListingSearch $search): array
    {
        $viewer = $this->viewer();
        $ids = array_slice(array_map('intval', $search->listing_ids ?? []), 0, self::LIMIT);
        if ($ids === []) {
            $this->hidden = 0;

            return [];
        }

        $allowed = OrgAccess::directions($viewer);
        $listings = [];
        $error = null;
        foreach (app(RealEstateClient::class)->listingsByIds($ids) as $listing) {
            if ($listing instanceof RealEstateException) {
                $error ??= $listing;

                continue;
            }
            $direction = $listing?->direction();
            if (! $listing instanceof Listing || $direction === null || ! in_array($direction, $allowed, true)) {
                continue;
            }
            $listings[] = $listing;
        }
        $this->incomplete = $error !== null;
        if ($error !== null) {
            Notification::make()->danger()->title($error->userMessage())->send();
        }
        // Ошибка чтения — не «скрыто»: такие карточки покажет предупреждение о неполном списке.
        $this->hidden = $error !== null ? 0 : count($ids) - count($listings);

        return ListingRows::build($viewer, $listings);
    }

    /** @return list<string> */
    public function siteLines(): array
    {
        return MySearches::siteLines($this->search);
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
