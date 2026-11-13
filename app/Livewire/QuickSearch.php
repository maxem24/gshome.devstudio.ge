<?php

namespace App\Livewire;

use App\Access\OrgAccess;
use App\Crm\Exceptions\CrmForbidden;
use App\Enums\Direction;
use App\Filament\Pages\Listings;
use App\Filament\Pages\MySearches;
use App\Models\User;
use App\RealEstate\DictionaryOptions;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\RealEstateClient;
use App\Search\SiteSearches;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** Быстрый поиск на главной и над «Объектами»: «Свежее с сайтов» (фоновый поиск) или «По базе» (экран объектов с фильтрами). */
final class QuickSearch extends Component
{
    private const SESSION_KEY = 'quick_search.last';

    /** 'fresh' | 'base' */
    public string $mode = 'fresh';

    /** true над «Объектами» */
    public bool $compact = false;

    public ?string $deal = null;

    public ?int $property_type_id = null;

    public ?int $city_id = null;

    /** @var list<int> */
    public array $district_id = [];

    /** @var list<int> */
    public array $subdistrict_id = [];

    public ?int $price_usd_min = null;

    public ?int $price_usd_max = null;

    public ?int $area_min = null;

    public ?int $area_max = null;

    /** @var list<string> */
    public array $bedrooms = [];

    public function mount(): void
    {
        $directions = OrgAccess::directions($this->viewer());
        $last = session(self::SESSION_KEY);

        if (is_array($last)) {
            $this->mode = ($last['mode'] ?? null) === 'base' ? 'base' : 'fresh';
            $this->deal = filled($last['deal'] ?? null) ? (string) $last['deal'] : null;
            $this->property_type_id = filled($last['property_type_id'] ?? null) ? (int) $last['property_type_id'] : null;
            $this->city_id = filled($last['city_id'] ?? null) ? (int) $last['city_id'] : null;
            $this->district_id = array_values(array_map('intval', (array) ($last['district_id'] ?? [])));
            $this->subdistrict_id = array_values(array_map('intval', (array) ($last['subdistrict_id'] ?? [])));
            foreach (['price_usd_min', 'price_usd_max', 'area_min', 'area_max'] as $key) {
                $this->{$key} = filled($last[$key] ?? null) ? (int) $last[$key] : null;
            }
            $this->bedrooms = array_values(array_map('strval', (array) ($last['bedrooms'] ?? [])));

            return;
        }

        $this->deal = FindListing::normalizeDeal([], $directions)['deal'] ?? null;
        $this->property_type_id = $this->dictionaryId('property-types', 'apartment');
        $this->city_id = $this->dictionaryId('cities', 'tbilisi');
    }

    public function canSubmit(): bool
    {
        return $this->city_id !== null || $this->district_id !== []
            || $this->property_type_id !== null
            || $this->price_usd_min !== null || $this->price_usd_max !== null;
    }

    /** SS ищет только по типу и месту: без них поиск по сайтам пропустит этот запрос. */
    public function ssWillSkip(): bool
    {
        return $this->property_type_id === null || ($this->city_id === null && $this->district_id === []);
    }

    public function useBase(): void
    {
        $this->mode = 'base';
    }

    /** @return array<string, mixed> */
    public function formData(): array
    {
        return [
            'deal' => $this->deal,
            'property_type_id' => $this->property_type_id,
            'city_id' => $this->city_id,
            'district_id' => $this->district_id,
            'subdistrict_id' => $this->subdistrict_id,
            'price_usd_min' => $this->price_usd_min,
            'price_usd_max' => $this->price_usd_max,
            'area_min' => $this->area_min,
            'area_max' => $this->area_max,
            'bedrooms' => $this->bedrooms,
        ];
    }

    public function submit(): void
    {
        if (! $this->canSubmit()) {
            return;
        }

        $user = $this->viewer();
        $directions = OrgAccess::directions($user);
        $data = FindListing::normalizeDeal($this->formData(), $directions);
        session([self::SESSION_KEY => $data + ['mode' => $this->mode]]);

        if ($this->mode === 'base') {
            // Права проверяем на сервере: свойство Livewire могли подделать.
            if (! FindListing::inOwnDirections(Direction::tryFrom((string) ($data['deal'] ?? '')), $directions)) {
                Notification::make()->warning()->title(__('gshome.search.base_own_only'))->send();

                return;
            }
            $this->redirect(Listings::getUrl(['filters' => FindListing::baseFilters($data)]));

            return;
        }

        try {
            $start = app(SiteSearches::class)->start($user, $data);
        } catch (CrmForbidden) {
            abort(403);
        } catch (RealEstateRateLimited) {
            $this->fail(__('gshome.search.rate_limited'));

            return;
        } catch (RealEstateException $e) {
            $this->fail($e->userMessage());

            return;
        }

        MySearches::notifyStart($start);
    }

    private function fail(string $message): void
    {
        Notification::make()->danger()->title($message)->send();
    }

    /** Свой справочник по slug; нет записи или API молчит — поле остаётся пустым. */
    private function dictionaryId(string $name, string $slug): ?int
    {
        $rows = rescue(fn () => app(RealEstateClient::class)->dictionary($name), collect(), report: false);
        $row = $rows->first(fn (array $r): bool => ($r['slug'] ?? null) === $slug);

        return $row !== null ? (int) $row['id'] : null;
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        $directions = OrgAccess::directions($this->viewer());
        $dictionary = fn (string $name, array $params = []): array => DictionaryOptions::options($name, $params);

        return view('livewire.quick-search', [
            'dealOptions' => count($directions) > 1
                ? collect($directions)->mapWithKeys(fn (Direction $d): array => [$d->value => $d->getLabel()])->all()
                : [],
            'cities' => $dictionary('cities'),
            'types' => $dictionary('property-types'),
            'districts' => $this->city_id !== null ? $dictionary('districts', ['city_id' => $this->city_id]) : [],
            'collectorsOffline' => $this->mode === 'fresh' && app(RealEstateClient::class)->collectorsOnline() === false,
        ]);
    }
}
