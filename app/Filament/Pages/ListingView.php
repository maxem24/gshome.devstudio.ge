<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Access\Ownership;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Exceptions\PinTaken;
use App\Crm\Journal;
use App\Crm\Pins;
use App\Enums\DealStatus;
use App\Enums\Direction;
use App\Enums\OwnershipLabel;
use App\Enums\Role;
use App\Filament\Pages\Concerns\OpensDealsByPhone;
use App\Models\ListingPin;
use App\Models\SavedListing;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Listing;
use App\RealEstate\ListingDetails;
use App\RealEstate\ListingRows;
use App\RealEstate\RealEstateClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
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

    public static function getRoutePath(Panel $panel): string
    {
        return '/listings/{listing}';
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
        abort_if($l === null || $direction === null || ! in_array($direction, OrgAccess::directions($viewer), true), 404);

        // Просмотр пишем после проверки направления; mount идёт раз на загрузку страницы.
        Journal::record(Journal::LISTING_VIEWED, null, ['listing_id' => $l->id], $viewer);

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
            'description' => $l->description,
            'images' => $l->images,
            'additional' => ListingDetails::additional($l, $this->amenityCatalog()),
            'seller' => $l->sellerCategory->getLabel().($l->agencyName ? " · {$l->agencyName}" : ''),
            'contact_name' => $l->contactName,
            'contact_phone' => $l->contactPhone,
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
            ], $l->clusterListings),
        ];
    }

    public function getTitle(): string
    {
        return $this->card['title'] ?? __('gshome.listings.object');
    }

    protected function getHeaderActions(): array
    {
        return [
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

    /** Активное закрепление этой квартиры; не в снапшоте — читается на каждый рендер. */
    #[Computed]
    public function pin(): ?ListingPin
    {
        $listing = $this->currentListing();

        return $listing === null ? null : app(Pins::class)->activeFor($listing);
    }

    /** @return array{mine: bool, name: ?string, holder_id: int}|null */
    public function banner(): ?array
    {
        $pin = $this->pin();
        if ($pin === null) {
            return null;
        }

        $viewer = $this->viewer();
        $holder = $pin->holder;
        // Имя — по тем же правилам, что у объявлений: своих людей и группы смотрящий видит, чужих нет.
        $named = $holder->id === $viewer->id
            || $viewer->role === Role::Owner
            || in_array((int) $holder->company_id, OrgAccess::identifiableCompanyIds($viewer), true);

        return ['mine' => $holder->id === $viewer->id, 'name' => $named ? $holder->name : null, 'holder_id' => $holder->id];
    }

    protected function dealPin(): ?ListingPin
    {
        return $this->pin();
    }

    protected function afterDealChange(): void
    {
        unset($this->pin);
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

    /** @return list<Action> */
    private function pinActions(): array
    {
        $manage = fn (): bool => ($pin = $this->pin()) !== null && app(Pins::class)->canManage($pin, $this->viewer());

        return [
            Action::make('takeInWork')
                ->label(__('gshome.crm.pins.take'))
                ->icon(Heroicon::OutlinedHandRaised)
                ->visible(fn (): bool => $this->card !== [] && $this->pin() === null)
                ->action(function (): void {
                    $listing = $this->currentListing();
                    try {
                        if ($listing === null) {
                            throw new CrmForbidden('Listing is gone.');
                        }
                        app(Pins::class)->take($listing, $this->viewer());
                        Notification::make()->title(__('gshome.crm.pins.taken'))->success()->send();
                    } catch (PinTaken) {
                        Notification::make()->title(__('gshome.crm.pins.already_taken'))->warning()->send();
                    } catch (CrmForbidden) {
                        Notification::make()->title(__('gshome.crm.pins.forbidden'))->danger()->send();
                    }
                    unset($this->pin);
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
                    unset($this->pin);
                }),
            Action::make('markUnavailable')
                ->label(__('gshome.crm.pins.unavailable'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('gray')
                ->requiresConfirmation()
                ->visible($manage)
                ->action(fn () => $this->closePin(fn (Pins $pins, ListingPin $pin) => $pins->markUnavailable($pin, $this->viewer()))),
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
        unset($this->pin);
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

        return OrgAccess::visibleUsers(OrgAccess::handOverScope($viewer, $pin->user_id))
            ->orderBy('name')->get()
            ->filter(fn (User $u): bool => $pins->canReceive($pin, $u, $viewer))
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
