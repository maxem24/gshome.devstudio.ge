<?php

namespace App\Filament\Pages;

use App\Access\OrgAccess;
use App\Enums\Role;
use App\Models\ListingPin;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Listing;
use App\RealEstate\RealEstateClient;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Мои объекты: активные закрепления; руководитель видит своих людей и может сузить до одного. */
class MyObjects extends Page
{
    /** Сколько карточек читаем за раз: у API нет выборки по списку id. */
    public const LIMIT = 50;

    protected string $view = 'filament.pages.my-objects';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $slug = 'my-objects';

    protected static ?int $navigationSort = 2;

    /** Фильтр «агент» (только для руководителей); пусто — все видимые. */
    public ?int $agent = null;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('gshome.crm.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('gshome.crm.my_objects.title');
    }

    public function getTitle(): string
    {
        return __('gshome.crm.my_objects.title');
    }

    public function canFilterByAgent(): bool
    {
        return $this->viewer()->role !== Role::Agent;
    }

    /** @return array<int, string> id ⇒ имя людей, которых видит смотрящий */
    public function agentOptions(): array
    {
        return OrgAccess::visibleUsers($this->viewer())->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array{rows: list<array<string, mixed>>, error: ?string} */
    public function rows(): array
    {
        $viewer = $this->viewer();
        $pins = ListingPin::query()->visibleTo($viewer)->with('holder')
            ->where('status', ListingPin::ACTIVE)
            ->whereIn('direction', array_map(fn ($d) => $d->value, OrgAccess::directions($viewer)))
            ->when($this->canFilterByAgent() && $this->agent !== null, fn ($q) => $q->where('user_id', $this->agent))
            ->latest('last_activity_at')->latest('id')
            ->limit(self::LIMIT)->get();

        $cards = [];
        $error = null;
        if ($pins->isNotEmpty()) {
            $cards = app(RealEstateClient::class)->listingsByIds($pins->pluck('listing_id')->map(fn ($id): int => (int) $id)->all());
        }

        $rows = [];
        foreach ($pins as $pin) {
            $card = $cards[$pin->listing_id] ?? null;
            if ($card instanceof RealEstateException) {
                $error ??= $card;
                $card = null;
            }
            $listing = $card instanceof Listing ? $card : null;
            $rows[] = [
                'pin_id' => $pin->id,
                'listing_id' => $pin->listing_id,
                'url' => $listing !== null ? ListingView::getUrl(['listing' => $listing->id]) : null,
                'title' => $listing !== null ? ($listing->title ?? '—') : '—',
                'price' => $listing?->priceLabel(),
                'address' => $listing?->address,
                'facts' => $listing?->facts(),
                'direction' => $pin->direction->getLabel(),
                'holder' => $pin->holder->name,
                'unavailable' => $listing === null,
            ];
        }

        return ['rows' => $rows, 'error' => $error?->userMessage()];
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
