<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Access\Ownership;
use App\Crm\ClientProfile;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Exceptions\PinTaken;
use App\Crm\Journal;
use App\Crm\ListingObjects;
use App\Crm\MatchRows;
use App\Crm\Pins;
use App\Enums\DealStatus;
use App\Enums\Direction;
use App\Enums\OwnershipLabel;
use App\Enums\Role;
use App\Enums\UnavailableReason;
use App\Filament\Pages\Concerns\OpensDealsByPhone;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Jobs\PollListingRefresh;
use App\Models\ListingObject;
use App\Models\ListingPin;
use App\Models\ListingSearch;
use App\Models\SavedListing;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\Exceptions\RealEstateRefreshNotFound;
use App\RealEstate\Exceptions\RealEstateUnavailable;
use App\RealEstate\Listing;
use App\RealEstate\ListingDetails;
use App\RealEstate\ListingRefresh;
use App\RealEstate\ListingRefreshItem;
use App\RealEstate\ListingRows;
use App\RealEstate\RealEstateClient;
use App\Search\LinkImports;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/** Карточка объекта (ТЗ 4.4). Чужое направление, посуточно, залог, снятое — 404. */
class ListingView extends Page
{
    use OpensDealsByPhone;

    protected string $view = 'filament.pages.listing-view';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'listing';

    /** @var array<string, mixed> */
    #[Locked]
    public array $card = [];

    #[Locked]
    public ?string $error = null;

    /** Id идущей актуализации в Real Estate; Locked — подменить из браузера нельзя (чужие Real Estate всё равно не отдаст). */
    #[Locked]
    public ?string $refreshId = null;

    /**
     * Готовое к показу состояние актуализации (пусто — её не запускали).
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $refresh = [];

    /** Не показывать кнопку актуализации до этого времени (ISO): API ответил 429 на запуск. */
    #[Locked]
    public ?string $retryUntil = null;

    public static function getRoutePath(Panel $panel): string
    {
        return '/listings/{listing}';
    }

    private function foundBySearch(User $viewer, Listing $listing, Direction $direction): bool
    {
        $id = request()->query('search');
        if (! is_string($id) || ! ctype_digit($id) || (int) $id <= 0) {
            return false;
        }
        $search = ListingSearch::query()->visibleTo($viewer)->with('user')->find((int) $id);

        return $search !== null
            && in_array($listing->id, array_map('intval', $search->listing_ids ?? []), true)
            && in_array($direction, $search->directions(), true);
    }

    public function mount(int|string $listing): void
    {
        $viewer = $this->viewer();
        abort_unless(ctype_digit((string) $listing) && (int) $listing > 0, 404);

        try {
            $l = app(RealEstateClient::class)->listing((int) $listing);
        } catch (RealEstateException $e) {
            $this->error = $e->userMessage();

            return;
        }

        $direction = $l?->direction();
        abort_if($l === null || $direction === null, 404);
        // Чужое направление открывается только из собственного поиска по сайтам, в выдачу которого объявление входит,
        // или по своей ссылке «По ссылке».
        abort_unless(in_array($direction, OrgAccess::directions($viewer), true) || $this->foundBySearch($viewer, $l, $direction)
            || LinkImports::sentBy($viewer, $l->id, request()->query('link')), 404);

        // Просмотр пишем после проверки направления; mount идёт раз на загрузку страницы.
        Journal::record(Journal::LISTING_VIEWED, null, ['listing_id' => $l->id], $viewer);

        $this->buildCard($l, $direction, $viewer);

        // Снимок объекта обновляем тем же ответом API, без второго запроса — и только со «своего»
        // объявления: копия из группы дублей может стоить иначе и не знать микрорайона.
        $object = app(ListingObjects::class)->findOpen($l->id, $l->clusterId);
        if ($object !== null && $object->listing_id === $l->id) {
            app(ListingObjects::class)->snapshot($object, $l);
        }
    }

    /** Собирает $card из объявления; зовётся из mount() и после актуализации. */
    private function buildCard(Listing $l, Direction $direction, User $viewer): void
    {
        $numbers = [$l->contactPhoneE164, ...$l->clusterPhonesE164, ...array_column($l->clusterListings, 'phone_e164')];
        $resolved = OrgAccess::ownershipMany($viewer, $numbers);
        $own = $l->contactPhoneE164 !== null ? ($resolved[$l->contactPhoneE164] ?? Ownership::other()) : Ownership::other();
        $group = ListingRows::groupOwnership($own, $l, $resolved);
        $copy = ListingRows::duplicateCopy($l, $resolved);
        $toArray = fn (?Ownership $o): ?array => $o === null ? null : [
            'label' => $o->label->getLabel(), 'color' => ListingRows::color($o->label),
            'company' => $o->companyName, 'name' => $o->contactName, 'phone' => $o->contactPhone,
            'can_contact' => $o->contactPhone !== null,
            'same_owner' => $o->label === OwnershipLabel::LinkedCompany,
        ];

        $this->card = [
            'id' => $l->id,
            'direction_key' => $direction->value,
            'refreshable' => $l->refreshable,
            'partial' => $l->partial,
            'cluster_id' => $l->clusterId,
            'title' => $l->title ?? '—',
            'direction' => $direction->getLabel(),
            'price' => $l->priceLabel(),
            'price_gel' => $l->priceGel !== null && $direction === Direction::Sale ? number_format($l->priceGel, 0, '.', ' ').' ₾' : null,
            'negotiable' => $l->negotiable,
            'facts' => $l->facts(),
            'rooms' => $l->rooms === null ? null : ($l->rooms === 0 ? __('gshome.listings.studio') : (string) $l->rooms),
            'area' => ListingDetails::squareMeters($l->areaTotal),
            'floor' => self::floor($l->floor, $l->floorsTotal),
            'property_type' => $l->propertyType,
            'address' => $l->address,
            'district' => collect([$l->city, $l->district])->filter()->implode(', '),
            'map' => $l->lat !== null && $l->lng !== null ? "https://maps.google.com/?q={$l->lat},{$l->lng}" : null,
            'description' => ListingDetails::plainText($l->description),
            'images' => $l->images,
            'additional' => ListingDetails::additional($l, $this->amenityCatalog()),
            'seller' => $l->sellerCategory->getLabel().($l->agencyName ? " · {$l->agencyName}" : ''),
            'contact_name' => $l->contactName,
            'contact_phone' => $l->contactPhone,
            'call_href' => self::telHref($l->contactPhone),
            'viber' => $l->hasViber,
            'whatsapp' => $l->hasWhatsapp,
            'source' => $l->source === 'myhome_ge' ? 'myhome.ge' : 'ss.ge',
            'source_url' => $l->sourceUrl,
            'created_at' => $l->sourceCreatedAt?->format('d.m.Y'),
            'own' => $toArray($own),
            'group' => $toArray($group),
            'company_line' => filled($own->companyName) ? __('gshome.listings.company_line', ['name' => $own->companyName]) : null,
            'same_owner' => $own->label === OwnershipLabel::LinkedCompany,
            'agency_in_group' => $copy['agency_in_group'],
            'owner_still_listed' => $copy['owner_still_listed'],
            'cluster' => array_map(fn (array $m): array => [
                'id' => $m['id'],
                'url' => $m['id'] > 0 ? self::getUrl(['listing' => $m['id']]) : null,
                'source' => $m['source'] === 'myhome_ge' ? 'myhome.ge' : 'ss.ge',
                'source_url' => $m['source_url'],
                'seller' => $m['seller_category']->getLabel().($m['agency_name'] ? " · {$m['agency_name']}" : ''),
                'price' => $m['price_usd'] !== null ? '$'.number_format($m['price_usd'], 0, '.', ' ') : '—',
                'ownership' => $toArray($m['phone_e164'] !== null ? ($resolved[$m['phone_e164']] ?? Ownership::other()) : Ownership::other()),
                'soft_match' => self::softMatch($m['match'] ?? null),
            ], $l->clusterListings),
            'soft_match' => self::softMatch($l->match),
        ];
    }

    private static function telHref(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $phone);

        return is_string($digits) && $digits !== '' && $digits !== '+' ? 'tel:'.$digits : null;
    }

    /**
     * Мягкая склейка — подпись «возможно, та же квартира · 70 %» и фраза API, почему склеили. Полная — null.
     *
     * @param  array{type: string, confidence: int, explanation: ?string}|null  $match
     * @return array{label: string, confidence: int, explanation: ?string}|null
     */
    private static function softMatch(?array $match): ?array
    {
        if (($match['type'] ?? null) !== 'soft') {
            return null;
        }

        return [
            'label' => __('gshome.listings.soft_match', ['confidence' => $match['confidence']]),
            'confidence' => $match['confidence'],
            'explanation' => $match['explanation'],
        ];
    }

    private function refreshAction(): Action
    {
        return Action::make('collectFullInfo')
            ->label(__('gshome.listings.refresh.button'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->visible(fn (): bool => (bool) ($this->card['refreshable'] ?? false) && $this->refreshId === null
                && ($this->retryUntil === null || now()->greaterThanOrEqualTo(Carbon::parse($this->retryUntil))))
            ->action(function (): void {
                $id = (int) ($this->card['id'] ?? 0);
                abort_unless($id > 0 && ($this->card['refreshable'] ?? false), 404);
                try {
                    $state = app(RealEstateClient::class)->startRefresh($id);
                } catch (RealEstateException $e) {
                    if ($e instanceof RealEstateRateLimited) {
                        $this->retryUntil = now()->addSeconds($e->retryAfter)->toIso8601String();
                    }
                    Notification::make()->title($e->userMessage())->danger()->send();

                    return;
                }
                if ($state === null) {
                    Notification::make()->title(__('gshome.listings.refresh.not_found'))->danger()->send();

                    return;
                }

                Journal::record(Journal::LISTING_REFRESH_STARTED, null, ['listing_id' => $id], $this->viewer());
                Notification::make()->title(__('gshome.listings.refresh.started'))->success()->send();
                $this->refreshId = $state->id;
                if (! $state->finished()) {
                    // Менеджер может уйти со страницы: ждать конца и звонить в колокольчик будет очередь.
                    PollListingRefresh::start($state->id, $id, $this->viewer()->id);
                }
                // Подсказка «можно уйти» обещает свежие данные при следующем открытии: старый кеш карточки её нарушил бы.
                app(RealEstateClient::class)->forgetCard($id);
                $this->absorbRefresh($state);
            });
    }

    /** Опрос из blade (wire:poll): читает состояние актуализации и, когда она закончилась, применяет результат. */
    public function pollRefresh(): void
    {
        if ($this->refreshId === null) {
            return;
        }
        // 429 на прошлом опросе: до retry_until в API не ходим.
        $until = $this->refresh['retry_until'] ?? null;
        if (is_string($until) && now()->lessThan(Carbon::parse($until))) {
            return;
        }

        try {
            $state = app(RealEstateClient::class)->refreshState($this->refreshId);
        } catch (RealEstateRefreshNotFound) {
            // Real Estate больше не знает эту актуализацию — ждать нечего.
            $this->refreshId = null;
            $this->refresh['finished'] = true;
            $this->refresh['lost'] = true;
            $this->refresh['error'] = null;
            $this->markNotWaited();

            return;
        } catch (RealEstateRateLimited $e) {
            $this->refresh['retry_until'] = now()->addSeconds($e->retryAfter)->toIso8601String();
            $this->refresh['error'] = $e->userMessage();
            $this->stopOnDeadline();

            return;
        } catch (RealEstateUnavailable $e) {
            // Сбой связи при опросе — не повод бросать актуализацию: покажем причину и спросим ещё раз.
            $this->refresh['error'] = $e->userMessage();
            $this->stopOnDeadline();

            return;
        } catch (RealEstateException $e) {
            // Доступ закрыт или запрос отвергнут: повтор ничего не изменит (и каждые 2 с писал бы в лог).
            $this->refreshId = null;
            $this->refresh['finished'] = true;
            $this->refresh['error'] = $e->userMessage();
            $this->markNotWaited();

            return;
        }

        $this->absorbRefresh($state);
        $this->stopOnDeadline();
    }

    /** Дедлайн актуализации + 15 с прошли, а конца нет — перестаём ждать. */
    private function stopOnDeadline(): void
    {
        $deadline = $this->refresh['deadline'] ?? null;
        if ($this->refreshId !== null && is_string($deadline) && now()->greaterThan(Carbon::parse($deadline)->addSeconds(15))) {
            $this->refreshId = null;
            $this->refresh['finished'] = true;
            $this->refresh['error'] = null;
            $this->refresh['timeout'] = __('gshome.listings.refresh.timeout');
            $this->markNotWaited();
        }
    }

    /** Ждать больше не будем: то, что так и осталось «проверяем…», честно назвать «не дождались». */
    private function markNotWaited(): void
    {
        foreach ($this->refresh['items'] ?? [] as $n => $item) {
            if ($item['pending'] ?? false) {
                $this->refresh['items'][$n]['pending'] = false;
                $this->refresh['items'][$n]['text'] = __('gshome.listings.refresh.outcomes.not_waited');
            }
        }
    }

    /** Состояние API → $refresh; при done/stale ещё и применяет результат к карточке и снимает refreshId. */
    private function absorbRefresh(ListingRefresh $state): void
    {
        $this->refresh = [
            'status' => $state->status,
            'finished' => $state->finished(),
            'eta_minutes' => max(1, (int) ceil($state->etaSeconds / 60)),
            'poll_ms' => $state->pollAfterMs,
            'deadline' => $state->deadlineAt?->toIso8601String(),
            'items' => array_map(fn (ListingRefreshItem $i): array => [
                'site' => self::siteLabel($i->source),
                'url' => $i->sourceUrl,
                'text' => self::itemText($i, $state->finished()),
                'pending' => $i->outcome === 'pending',
            ], $state->items),
            'truncated' => $state->truncated,
            'error' => null,
            'timeout' => null,
            'lost' => false,
            'retry_until' => null,
            'removed' => false,
            'removed_at' => null,
            'price_before' => null,
            'summary' => null,
            'stale' => null,
        ];
        if (! $state->finished()) {
            return;
        }

        // Результат уже на экране: очереди звонить в колокольчик незачем.
        PollListingRefresh::markShown($state->id);
        $this->refreshId = null;
        $this->applyRefreshResult($state);
    }

    private function applyRefreshResult(ListingRefresh $state): void
    {
        $id = (int) ($this->card['id'] ?? 0);
        // Результат уже у Real Estate: кеш карточки на 60 с больше не нужен ни на одном языке.
        app(RealEstateClient::class)->forgetCard($id);

        $this->refresh['summary'] = collect($state->items)
            ->map(fn (ListingRefreshItem $i): string => self::siteLabel($i->source).' — '.self::itemText($i, true))
            ->unique()->implode('; ');

        $raw = $state->listing;
        if ($raw !== null && ($raw['status'] ?? null) === 'inactive') {
            $this->refresh['removed'] = true;
            // Дата снятия — у самой карточки, иначе у любой из копий.
            $at = null;
            foreach ($state->items as $item) {
                if ($item->deactivatedAt !== null && ($at === null || $item->id === $id)) {
                    $at = $item->deactivatedAt;
                }
            }
            $this->refresh['removed_at'] = $at?->timezone(config('app.timezone'))->format('d.m.Y H:i');
        } elseif ($raw !== null) {
            $this->refillCard($raw);
        }

        if ($state->stale()) {
            $checked = collect($state->items)->pluck('checkedAt')->filter()->sort()->first();
            $this->refresh['stale'] = __('gshome.listings.refresh.stale', [
                'date' => $checked instanceof Carbon ? $checked->timezone(config('app.timezone'))->format('d.m.Y H:i') : '—',
            ]);
        }
    }

    /**
     * Пересобирает карточку из данных актуализации (без нового GET). Направление то же, что открыл mount:
     * если объявление перешло в направление, которое смотрящему недоступно, карточку не трогаем.
     *
     * @param  array<string, mixed>  $raw
     */
    private function refillCard(array $raw): void
    {
        $viewer = $this->viewer();
        try {
            $l = Listing::fromApi($raw, app()->getLocale());
        } catch (\TypeError|\ErrorException|\UnexpectedValueException $e) {
            Log::warning('Real Estate API: неожиданный формат карточки в актуализации', ['exception' => $e]);

            return;
        }
        $direction = $l->direction();
        if ($l->id !== (int) ($this->card['id'] ?? 0) || $direction === null
            || ($direction->value !== ($this->card['direction_key'] ?? null) && ! in_array($direction, OrgAccess::directions($viewer), true))) {
            return;
        }

        $before = $this->card['price'] ?? null;
        $this->buildCard($l, $direction, $viewer);
        if ($before !== null && $before !== $this->card['price']) {
            $this->refresh['price_before'] = $before;
        }
    }

    private static function siteLabel(string $source): string
    {
        return $source === 'myhome_ge' ? 'myhome.ge' : 'ss.ge';
    }

    /** Итог по одной копии: текст из API (`error`) выводим как есть. */
    private static function itemText(ListingRefreshItem $item, bool $finished): string
    {
        if ($item->outcome === 'pending' && $finished) {
            return __('gshome.listings.refresh.outcomes.not_waited');
        }

        return $item->outcome === 'failed'
            ? ($item->error ?? __('gshome.listings.refresh.outcomes.failed'))
            : __('gshome.listings.refresh.outcomes.'.$item->outcome);
    }

    public function getTitle(): string
    {
        return $this->card['title'] ?? __('gshome.listings.object');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->refreshAction(),
            ...$this->pinActions(),
            $this->contactAction('contactOwn', 'own'),
            $this->contactAction('contactGroup', 'group', __('gshome.listings.contact_group')),
            Action::make('source')->label(__('gshome.listings.source_listing'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)->color('gray')
                ->url(fn (): ?string => $this->card['source_url'] ?? null, shouldOpenInNewTab: true)
                ->visible(fn (): bool => isset($this->card['source_url'])),
            Action::make('saveForLater')
                ->label(fn (): string => $this->isSaved()
                    ? __('gshome.saved.remove')
                    : __('gshome.listings.save_for_later'))
                ->icon(fn (): Heroicon => $this->isSaved() ? Heroicon::Bookmark : Heroicon::OutlinedBookmark)
                ->color('gray')
                ->visible(fn (): bool => (int) ($this->card['id'] ?? 0) > 0)
                ->action(function (): void {
                    $id = (int) ($this->card['id'] ?? 0);
                    abort_unless($id > 0, 404);
                    $saved = SavedListing::query()
                        ->where('user_id', $this->viewer()->id)
                        ->where('listing_id', $id);
                    if ($saved->exists()) {
                        $saved->delete();

                        return;
                    }
                    SavedListing::query()->firstOrCreate([
                        'user_id' => $this->viewer()->id,
                        'listing_id' => $id,
                    ]);
                }),
        ];
    }

    /** Открытый объект этой квартиры; не в снапшоте — читается на каждый рендер. Находит и по снятому объявлению. */
    #[Computed]
    public function object(): ?ListingObject
    {
        $id = (int) ($this->card['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return app(ListingObjects::class)->findOpen($id, $this->card['cluster_id'] ?? null);
    }

    /** Пин, которым смотрящий вправе управлять: свой или самый старый из видимых ему. */
    #[Computed]
    public function pin(): ?ListingPin
    {
        $object = $this->object();

        return $object === null ? null : app(Pins::class)->managed($object, $this->viewer());
    }

    /**
     * «Ведут»: только люди своей компании и компаний по обмену (владелец — все); прочие не показываются.
     *
     * @return array{holders: list<array{name: ?string, mine: bool}>, preferences: list<string>, closed: ?array{reason: string, at: string, name: ?string}}|null
     */
    public function banner(): ?array
    {
        $viewer = $this->viewer();
        $pins = app(Pins::class);
        $object = $this->object();

        $holders = [];
        if ($object !== null) {
            $visible = $viewer->role === Role::Owner ? null : OrgAccess::identifiableCompanyIds($viewer);
            foreach ($pins->activeOf($object) as $held) {
                $holder = $held->holder;
                $mine = $holder->id === $viewer->id;
                if ($mine || $visible === null || in_array((int) $holder->company_id, $visible, true)) {
                    $holders[] = ['name' => $pins->nameFor($holder, $viewer), 'mine' => $mine];
                }
            }
        }

        $pin = $this->pin();
        // Комментарий собственника — только тем, кто ведёт объект, а не всей группе.
        $preferences = $pin !== null && $pins->canManage($pin, $viewer) ? ClientProfile::linesFor($pin->preferences ?? []) : [];

        $closed = $object === null ? $this->closedBanner() : null;

        if ($holders === [] && $preferences === [] && $closed === null) {
            return null;
        }

        return ['holders' => $holders, 'preferences' => $preferences, 'closed' => $closed];
    }

    /** @return array{reason: string, at: string, name: ?string}|null */
    private function closedBanner(): ?array
    {
        $id = (int) ($this->card['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $clusterId = $this->card['cluster_id'] ?? null;

        $closed = ListingObject::query()
            ->where('status', ListingObject::CLOSED)
            ->whereIn('closed_reason', ['won', 'rented_elsewhere', 'fake'])
            ->where(fn ($q) => $q->where('listing_id', $id)->when($clusterId !== null, fn ($q) => $q->orWhere('object_key', 'c:'.$clusterId)))
            ->latest('closed_at')->latest('id')
            ->first();
        if ($closed === null) {
            return null;
        }

        $by = $closed->closed_by === null ? null : User::query()->find($closed->closed_by);

        return [
            'reason' => (string) $closed->closed_reason,
            'at' => $closed->closed_at?->timezone(config('app.timezone'))->format('d.m.Y') ?? '—',
            'name' => $by === null ? null : app(Pins::class)->nameFor($by, $this->viewer()),
        ];
    }

    /**
     * «Вероятные клиенты»: только на объекте аренды и только тем, кто его ведёт.
     *
     * @return list<array{score: ?int, enough: bool, label: string, sub: ?string, url: string, mismatches: list<string>}>|null
     */
    #[Computed]
    public function matches(): ?array
    {
        $pin = $this->pin();
        if ($pin === null || $pin->direction !== Direction::Rent || ! app(Pins::class)->canManage($pin, $this->viewer())) {
            return null;
        }

        // Опрос актуализации перерисовывает страницу раз в 2 секунды — подбор не пересчитываем на каждом.
        // updated_at в ключе: после правки предпочтений список обновится сразу.
        return Cache::remember('matches:pin:'.$pin->id.':'.$pin->updated_at?->timestamp, 30, fn (): array => MatchRows::forPin($pin));
    }

    protected function dealPin(): ?ListingPin
    {
        return $this->pin();
    }

    protected function afterDealChange(): void
    {
        unset($this->pin, $this->object);
    }

    private function currentListing(): ?Listing
    {
        $id = (int) ($this->card['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        try {
            return app(RealEstateClient::class)->listing($id);
        } catch (RealEstateException) {
            return null;
        }
    }

    private static function reasonOf(mixed $value): ?UnavailableReason
    {
        return $value instanceof UnavailableReason ? $value : UnavailableReason::tryFrom(is_string($value) ? $value : '');
    }

    /** «Взять в работу»: объявление живо, направление доступно, своего участия в объекте ещё нет. */
    private function canTake(): bool
    {
        if ($this->card === [] || ($this->refresh['removed'] ?? false)) {
            return false;
        }
        $viewer = $this->viewer();
        $direction = Direction::tryFrom((string) ($this->card['direction_key'] ?? ''));
        if ($direction === null || ! in_array($direction, OrgAccess::directions($viewer), true)) {
            return false;
        }
        $object = $this->object();

        return $object === null || app(Pins::class)->mine($object, $viewer) === null;
    }

    /** @return list<Action> */
    private function pinActions(): array
    {
        $manage = fn (): bool => ($pin = $this->pin()) !== null && app(Pins::class)->canManage($pin, $this->viewer());

        return [
            Action::make('takeInWork')
                ->label(__('gshome.crm.pins.take'))
                ->icon(Heroicon::OutlinedHandRaised)
                ->visible(fn (): bool => $this->canTake())
                ->action(function (): void {
                    $listing = $this->currentListing();
                    try {
                        if ($listing === null) {
                            throw new CrmForbidden('Listing is gone.');
                        }
                        app(Pins::class)->take($listing, $this->viewer());
                        Notification::make()->title(__('gshome.crm.pins.taken'))->success()->send();
                    } catch (PinTaken) {
                        Notification::make()->title(__('gshome.crm.pins.already_mine'))->warning()->send();
                    } catch (CrmForbidden) {
                        Notification::make()->title(__('gshome.crm.pins.forbidden'))->danger()->send();
                    }
                    unset($this->pin, $this->object);
                }),
            $this->openDealAction(),
            Action::make('transferPin')
                ->label(__('gshome.crm.pins.transfer'))
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->visible($manage)
                ->schema([
                    Select::make('to')->label(__('gshome.crm.pins.transfer_to'))->required()->searchable()
                        ->options(fn (): array => $this->recipients()),
                ])
                ->action(function (array $data): void {
                    $pin = $this->pin();
                    $to = User::query()->find((int) ($data['to'] ?? 0));
                    try {
                        if ($pin === null || $to === null) {
                            throw new CrmForbidden('Nothing to transfer.');
                        }
                        app(Pins::class)->transfer($pin, $to, $this->viewer());
                        Notification::make()->title(__('gshome.crm.pins.transferred'))->success()->send();
                    } catch (CrmForbidden) {
                        Notification::make()->title(__('gshome.crm.pins.forbidden'))->danger()->send();
                    }
                    unset($this->pin, $this->object);
                }),
            Action::make('landlordPreferences')
                ->label(__('gshome.crm.pins.preferences'))
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->visible($manage)
                ->modalHeading(__('gshome.crm.pins.preferences_title'))
                ->modalSubmitActionLabel(__('filament-actions::edit.single.modal.actions.save.label'))
                ->schema(ClientForm::profileFields(landlord: true))
                ->fillForm(fn (): array => $this->pin()->preferences ?? [])
                ->action(function (array $data): void {
                    $pin = $this->pin();
                    try {
                        if ($pin === null) {
                            throw new CrmForbidden('Nothing to edit.');
                        }
                        app(Pins::class)->savePreferences($pin, $data, $this->viewer());
                        Notification::make()->title(__('gshome.crm.pins.preferences_saved'))->success()->send();
                    } catch (CrmForbidden) {
                        Notification::make()->title(__('gshome.crm.pins.forbidden'))->danger()->send();
                    }
                    unset($this->pin, $this->object);
                }),
            Action::make('markUnavailable')
                ->label(__('gshome.crm.pins.unavailable'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('gray')
                ->visible($manage)
                ->modalHeading(__('gshome.crm.pins.unavailable'))
                ->schema([
                    Select::make('reason')->label(__('gshome.crm.pins.unavailable_reason'))
                        ->options(UnavailableReason::class)->required()->live()
                        ->helperText(fn (Get $get): ?string => ($reason = self::reasonOf($get('reason'))) === null ? null
                            : __($reason->closesObject() ? 'gshome.crm.pins.unavailable_closes_all' : 'gshome.crm.pins.unavailable_closes_mine')),
                ])
                ->action(function (array $data): void {
                    $reason = self::reasonOf($data['reason'] ?? null);
                    if ($reason === null) {
                        Notification::make()->title(__('gshome.crm.pins.forbidden'))->danger()->send();

                        return;
                    }
                    $this->closePin(fn (Pins $pins, ListingPin $pin) => $pins->markUnavailable($pin, $reason, $this->viewer()));
                }),
            Action::make('releasePin')
                ->label(__('gshome.crm.pins.release'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('gray')
                ->requiresConfirmation()
                ->visible($manage)
                ->action(function (): void {
                    $pin = $this->pin();
                    if ($pin !== null && $pin->deals()->where('status', DealStatus::Open)->exists()) {
                        Notification::make()->title(__('gshome.crm.pins.release_open_deals'))->warning()->send();

                        return;
                    }
                    $this->closePin(fn (Pins $pins, ListingPin $pin) => $pins->release($pin, $this->viewer()));
                }),
        ];
    }

    /** @param  \Closure(Pins, ListingPin): void  $close */
    private function closePin(\Closure $close): void
    {
        $pin = $this->pin();
        try {
            if ($pin === null) {
                throw new CrmForbidden('Nothing to close.');
            }
            $close(app(Pins::class), $pin);
            Notification::make()->title(__('gshome.crm.pins.closed'))->success()->send();
        } catch (CrmForbidden) {
            Notification::make()->title(__('gshome.crm.pins.forbidden'))->danger()->send();
        }
        unset($this->pin, $this->object);
    }

    /** @return array<int, string> кому можно передать: id ⇒ имя */
    private function recipients(): array
    {
        $pin = $this->pin();
        if ($pin === null) {
            return [];
        }

        $viewer = $this->viewer();
        $pins = app(Pins::class);
        // Кто уже ведёт этот объект, получить его ещё раз не может.
        $object = $this->object();
        $busy = $object === null ? [] : $pins->activeOf($object)->pluck('user_id')->all();

        return OrgAccess::visibleUsers(OrgAccess::handOverScope($viewer, $pin->user_id))
            ->orderBy('name')->get()
            ->filter(fn (User $u): bool => ! in_array($u->id, $busy, true) && $pins->canReceive($pin, $u, $viewer))
            ->mapWithKeys(fn (User $u): array => [$u->id => $u->name])
            ->all();
    }

    private function isSaved(): bool
    {
        $id = (int) ($this->card['id'] ?? 0);

        return $id > 0 && SavedListing::query()
            ->where('user_id', $this->viewer()->id)
            ->where('listing_id', $id)
            ->exists();
    }

    private function contactAction(string $name, string $key, ?string $label = null): Action
    {
        return Action::make($name)
            ->label($label ?? __('gshome.listings.contact'))
            ->icon(Heroicon::OutlinedPhone)
            ->visible(fn (): bool => (bool) ($this->card[$key]['can_contact'] ?? false))
            ->modalHeading(fn (): string => (string) ($this->card[$key]['name'] ?? $this->card[$key]['company'] ?? __('gshome.listings.contact_fallback')))
            ->modalDescription(fn (): string => (string) ($this->card[$key]['company'] ?? ''))
            ->modalContent(fn () => view('filament.pages.partials.contact', ['phone' => $this->card[$key]['phone'] ?? null]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('gshome.listings.close'));
    }

    /** @return list<array{slug: string, title: string}> */
    private function amenityCatalog(): array
    {
        try {
            return app(RealEstateClient::class)->dictionary('amenities')
                ->map(fn (array $row): array => [
                    'slug' => is_string($row['slug'] ?? null) ? $row['slug'] : '',
                    'title' => RealEstateClient::title($row),
                ])
                ->filter(fn (array $row): bool => $row['slug'] !== '')
                ->values()
                ->all();
        } catch (RealEstateException) {
            return [];
        }
    }

    private static function floor(?int $floor, ?int $total): ?string
    {
        if ($floor === null && $total === null) {
            return null;
        }
        if ($floor !== null && $total !== null) {
            return $floor.'/'.$total;
        }

        return (string) ($floor ?? $total);
    }

    protected function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
