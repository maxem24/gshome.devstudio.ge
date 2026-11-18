<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Pages\Concerns\RepeatsSearches;
use App\Models\ListingSearch;
use App\Models\User;
use App\Search\FiltersSummary;
use App\Search\SearchStart;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use UnitEnum;

/** Мои поиски по сайтам: свои и людей, которых видит человек; новые сверху. */
class MySearches extends Page implements HasTable
{
    use InteractsWithTable;
    use RepeatsSearches;

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
            ->query(fn () => ListingSearch::query()->visibleTo($this->viewer())->with('user')
                // Наверх — только свои закреплённые: чужие закладки руководителю не мешают.
                ->orderByRaw('CASE WHEN user_id = ? THEN pinned_at END DESC NULLS LAST', [$this->viewer()->getKey()])
                ->orderByDesc('id'))
            ->poll(fn (): ?string => ListingSearch::query()->visibleTo($this->viewer())->whereIn('status', ListingSearch::RUNNING)->exists() ? '10s' : null)
            ->recordUrl(fn (ListingSearch $record): string => SearchResult::getUrl(['search' => $record->getKey()]))
            ->columns([
                // Что искали — главное в строке; когда и кто — мелко под ним.
                TextColumn::make('filters')->label(__('gshome.search.mine.search'))
                    ->state(fn (ListingSearch $record): string => '№'.$record->getKey().' · '.$record->label())
                    ->weight(FontWeight::Medium)
                    ->icon(fn (ListingSearch $record): ?Heroicon => $record->pinned_at !== null && $this->owns($record) ? Heroicon::Bookmark : null)
                    ->description(fn (ListingSearch $record): string => collect([
                        // Своё название — крупно, а что искали — мелко под ним.
                        $record->title !== null ? FiltersSummary::make($record->filters, $record->user) : null,
                        $record->started_at?->timezone((string) config('crm.timezone'))->format('d.m.Y H:i'),
                        $viewer->role !== Role::Agent ? $record->user?->name : null,
                    ])->filter()->implode(' · '))
                    ->wrap(),
                TextColumn::make('status')->label(__('gshome.search.mine.status'))->badge()
                    ->state(fn (ListingSearch $record): string => __("gshome.search.status.{$record->status}"))
                    ->color(fn (ListingSearch $record): string => $record->statusColor())
                    ->description(fn (ListingSearch $record): ?string => $record->message !== null ? Str::limit($record->message, 90) : null)
                    ->tooltip(fn (ListingSearch $record): ?string => $record->message)
                    ->wrap(),
                ViewColumn::make('sites')->label(__('gshome.search.mine.sites'))->view('filament.searches.sites'),
                TextColumn::make('found')->label(__('gshome.search.mine.found'))
                    ->state(fn (ListingSearch $record): int => $record->shown())->numeric()
                    ->weight(FontWeight::Bold)->size('lg')
                    ->description(fn (ListingSearch $record): ?string => $record->status === 'done' ? $record->foundOnSitesNote() : null),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('gshome.search.open'))
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('primary')
                    ->button()
                    ->url(fn (ListingSearch $record): string => SearchResult::getUrl(['search' => $record->getKey()])),
                Action::make('again')
                    ->label(__('gshome.search.mine.again'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->button()
                    // Повторить может только автор: чужие поиски руководитель видит, но не запускает.
                    ->visible(fn (ListingSearch $record): bool => $record->user_id === $this->viewer()->getKey())
                    ->action(fn (ListingSearch $record) => $this->searchAgain((int) $record->getKey())),
                ActionGroup::make([
                    Action::make('rename')
                        ->label(__('gshome.search.mine.rename'))
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->visible(fn (ListingSearch $record): bool => $this->owns($record))
                        ->fillForm(fn (ListingSearch $record): array => ['title' => $record->title])
                        ->schema([
                            TextInput::make('title')->label(__('gshome.search.mine.title_field'))
                                ->placeholder(fn (ListingSearch $record): string => FiltersSummary::make($record->filters, $record->user))
                                ->maxLength(80),
                        ])
                        ->action(fn (ListingSearch $record, array $data) => $this->renameSearch((int) $record->getKey(), (string) ($data['title'] ?? ''))),
                    Action::make('pin')
                        ->label(__('gshome.search.mine.pin'))
                        ->icon(Heroicon::OutlinedBookmark)
                        ->visible(fn (ListingSearch $record): bool => $this->owns($record) && $record->pinned_at === null)
                        ->action(fn (ListingSearch $record) => $this->pinSearch((int) $record->getKey(), true)),
                    Action::make('unpin')
                        ->label(__('gshome.search.mine.unpin'))
                        ->icon(Heroicon::OutlinedBookmarkSlash)
                        ->visible(fn (ListingSearch $record): bool => $this->owns($record) && $record->pinned_at !== null)
                        ->action(fn (ListingSearch $record) => $this->pinSearch((int) $record->getKey(), false)),
                    Action::make('delete')
                        ->label(__('gshome.search.mine.delete'))
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (ListingSearch $record): bool => $this->owns($record) && ! $record->isRunning())
                        ->action(fn (ListingSearch $record) => $this->deleteSearch((int) $record->getKey())),
                ]),
            ])
            ->emptyStateHeading(__('gshome.search.mine.empty'))
            ->paginated([10, 25, 50]);
    }

    /** Менять поиск — только автору: руководитель чужие поиски видит, но не трогает. */
    private function owns(ListingSearch $search): bool
    {
        return $search->user_id === $this->viewer()->getKey();
    }

    private function ownSearch(int $id): ListingSearch
    {
        $search = ListingSearch::query()->visibleTo($this->viewer())->findOrFail($id);
        abort_unless($this->owns($search), 403);

        return $search;
    }

    public function renameSearch(int $id, string $title): void
    {
        $title = trim($title);
        $this->ownSearch($id)->update(['title' => $title === '' ? null : mb_substr($title, 0, 80)]);
    }

    public function pinSearch(int $id, bool $pin): void
    {
        $this->ownSearch($id)->update(['pinned_at' => $pin ? now() : null]);
    }

    public function deleteSearch(int $id): void
    {
        $search = $this->ownSearch($id);
        // Идущий поиск ещё пишет результат в эту запись — удалять нельзя.
        if ($search->isRunning()) {
            Notification::make()->warning()->title(__('gshome.search.mine.delete_running'))->send();

            return;
        }
        $search->delete();
        Notification::make()->success()->title(__('gshome.search.mine.deleted'))->send();
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
     * Сайты поиска для показа: имя, статус словами и цветом, сколько нашёл, пояснение.
     * Пометка о микрорайонах (note) уже стоит в общем сообщении поиска — из пояснения сайта её убираем.
     *
     * @return list<array{name: string, label: string, color: string, found: int, message: ?string}>
     */
    public static function sites(ListingSearch $search): array
    {
        $out = [];
        foreach ($search->sites ?? [] as $key => $site) {
            $status = (string) ($site['status'] ?? '');
            $label = __("gshome.search.site_status.{$status}");
            $message = (string) ($site['message'] ?? '');
            $note = (string) ($site['note'] ?? '');
            if ($note !== '' && $message !== $note && str_ends_with($message, $note)) {
                $message = rtrim(mb_substr($message, 0, mb_strlen($message) - mb_strlen($note)));
            }
            $out[] = [
                'name' => str_replace('_ge', '.ge', (string) $key),
                'label' => $label === "gshome.search.site_status.{$status}" ? $status : $label,
                'color' => match ($status) {
                    'done' => 'success',
                    'queued', 'running', 'leased', 'importing' => 'info',
                    'skipped', 'paused' => 'warning',
                    default => 'danger',
                },
                'found' => (int) ($site['found'] ?? 0),
                'message' => filled($message) ? $message : null,
            ];
        }

        return $out;
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
