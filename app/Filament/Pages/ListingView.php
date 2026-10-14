<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Access\Ownership;
use App\Enums\Direction;
use App\Models\SavedListing;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\ListingDetails;
use App\RealEstate\ListingRows;
use App\RealEstate\RealEstateClient;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/** Карточка объекта (ТЗ 4.4). Чужое направление, посуточно, залог, снятое — 404. */
class ListingView extends Page
{
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

        $numbers = [$l->contactPhoneE164, ...$l->clusterPhonesE164, ...array_column($l->clusterListings, 'phone_e164')];
        $resolved = OrgAccess::ownershipMany($viewer, $numbers);
        $own = $l->contactPhoneE164 !== null ? ($resolved[$l->contactPhoneE164] ?? Ownership::other()) : Ownership::other();
        $group = ListingRows::groupOwnership($own, $l, $resolved);
        $toArray = fn (?Ownership $o): ?array => $o === null ? null : [
            'label' => $o->label->getLabel(), 'color' => ListingRows::color($o->label),
            'company' => $o->companyName, 'name' => $o->contactName, 'phone' => $o->contactPhone,
            'can_contact' => $o->contactPhone !== null,
        ];

        $this->card = [
            'id' => $l->id,
            'title' => $l->title ?? '—',
            'direction' => $direction->getLabel(),
            'price' => $l->priceLabel(),
            'price_gel' => $l->priceGel !== null && $direction === Direction::Sale ? number_format($l->priceGel, 0, '.', ' ').' ₾' : null,
            'negotiable' => $l->negotiable,
            'facts' => $l->facts(),
            'rooms' => $l->rooms === null ? null : ($l->rooms === 0 ? __('gshome.listings.studio') : (string) $l->rooms),
            'area' => $l->areaTotal === null ? null : __('gshome.listings.square_meters', ['value' => self::number($l->areaTotal)]),
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
            'cluster' => array_map(fn (array $m): array => [
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

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
