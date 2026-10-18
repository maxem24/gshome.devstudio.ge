<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Models\SavedListing;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Listing;
use App\RealEstate\ListingRows;
use App\RealEstate\RealEstateClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/** Сохранённые на потом: только id этого человека, карточки читаются из Real Estate заново. */
class SavedListings extends Page implements HasTable
{
    use InteractsWithTable;

    /** Сколько карточек читаем за один заход: у API нет выборки по списку id. */
    public const LIMIT = 50;

    protected string $view = 'filament.pages.saved-listings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;

    protected static ?int $navigationSort = 0;

    /** @var array<int|string, array<string, mixed>> */
    #[Locked]
    public array $rows = [];

    #[Locked]
    public bool $loaded = false;

    #[Locked]
    public ?string $limitNote = null;

    /** Real Estate ответил ошибкой посреди чтения: показаны не все сохранённые. */
    #[Locked]
    public bool $incomplete = false;

    public static function getNavigationLabel(): string
    {
        return __('gshome.nav.saved');
    }

    public function getTitle(): string
    {
        return __('gshome.saved.title');
    }

    public function getSubheading(): ?string
    {
        return $this->limitNote;
    }

    public function mount(): void
    {
        $total = SavedListing::query()->where('user_id', $this->viewer()->id)->count();
        $this->limitNote = $total > self::LIMIT
            ? __('gshome.saved.limit', ['count' => self::LIMIT])
            : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if (! $this->loaded) {
                    $this->rows = $this->fetchRows();
                    $this->loaded = true;
                }

                return $this->rows;
            })
            ->paginated(false)
            ->recordUrl(fn (array $record): string => ListingView::getUrl(['listing' => $record['id']]))
            ->recordActions([
                Action::make('removeSaved')
                    ->label(__('gshome.saved.remove'))
                    ->icon(Heroicon::OutlinedBookmarkSlash)
                    ->color('gray')
                    ->button()
                    ->action(fn (array $record) => $this->forgetSaved((int) $record['id'])),
            ], position: RecordActionsPosition::AfterContent)
            ->columns([
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
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->emptyStateHeading(__('gshome.saved.empty_heading'))
            ->emptyStateDescription(__('gshome.saved.empty_description'));
    }

    public function forgetSaved(int $id): void
    {
        abort_unless($id > 0, 404);
        SavedListing::query()->where('user_id', $this->viewer()->id)->where('listing_id', $id)->delete();
        unset($this->rows[$id]);
        $this->flushCachedTableRecords();
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchRows(): array
    {
        $viewer = $this->viewer();
        $ids = SavedListing::query()
            ->where('user_id', $viewer->id)
            ->latest('id')
            ->limit(self::LIMIT)
            ->pluck('listing_id');

        $allowed = OrgAccess::directions($viewer);
        $listings = [];
        $error = null;
        foreach (app(RealEstateClient::class)->listingsByIds($ids->map(fn (mixed $id): int => (int) $id)->all()) as $listing) {
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
        if ($error !== null) {
            Notification::make()->danger()->title($error->userMessage())->send();
            $this->incomplete = true;
        }

        return ListingRows::build($viewer, $listings);
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
