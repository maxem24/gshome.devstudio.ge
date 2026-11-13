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
use Livewire\Attributes\Renderless;
use Livewire\Component;

/** Быстрый поиск на главной и над «Объектами»: «Свежее с сайтов» (фоновый поиск) или «По базе» (экран объектов с фильтрами). */
final class QuickSearch extends Component
{
    private const SESSION_KEY = 'quick_search.last';

    /** Быстрые суммы «до …» в панели цены, по сделке. */
    private const PRICE_PRESETS = ['sale' => [50000, 100000, 150000], 'rent' => [500, 800, 1200]];

    /** Быстрые диапазоны площади [от, до]. */
    private const AREA_PRESETS = [[null, 50], [50, 80], [80, 120], [120, null]];

    /** Подрайоны ищем не больше чем в стольких районах сразу — как в FindListing. */
    private const SUBDISTRICT_DISTRICTS = 5;

    /** Неразрывный пробел: «90 000» и «50 м²» не рвутся на телефоне. */
    private const NBSP = "\u{00A0}";

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
            $this->keepModeAllowed();

            return;
        }

        // Два направления и сделки в сессии нет: «Продажа» явно, чтобы пресеты и поиск шли по одной сделке.
        $this->deal = FindListing::normalizeDeal([], $directions)['deal'] ?? (count($directions) > 1 ? Direction::Sale->value : null);
        $this->property_type_id = $this->dictionaryId('property-types', 'apartment');
        $this->city_id = $this->dictionaryId('cities', 'tbilisi');
    }

    public function updatedDeal(): void
    {
        $this->keepModeAllowed();
    }

    /** Спальни приходят из браузера: оставляем только «1»…«5», подделка не должна ронять экран. */
    public function updatedBedrooms(): void
    {
        $this->normalizeBedrooms();
    }

    private function normalizeBedrooms(): void
    {
        $this->bedrooms = array_values(array_unique(array_map('strval', array_filter(
            (array) $this->bedrooms,
            fn (mixed $b): bool => (is_string($b) || is_int($b)) && in_array((string) $b, ['1', '2', '3', '4', '5'], true),
        ))));
    }

    public function updatedMode(): void
    {
        $this->keepModeAllowed();
    }

    /**
     * Поиск места для панели «Город → район → подрайон» — тот же серверный поиск, что в FindListing.
     * Пары [id, подпись], а не объект: числовые ключи в JS переупорядочились бы. У подрайона третьим — его район,
     * чтобы снятый район уносил свои подрайоны.
     *
     * @param  array<int, mixed>  $parents  город (для районов) или районы (для подрайонов)
     * @return list<array{0: int, 1: string, 2?: int}>
     */
    #[Renderless]
    public function placeOptions(string $level, string $term = '', array $parents = []): array
    {
        $parents = array_values(array_filter(array_map('intval', $parents)));

        if ($level === 'subdistricts') {
            $out = [];
            foreach (array_slice($parents, 0, self::SUBDISTRICT_DISTRICTS) as $district) {
                foreach (DictionaryOptions::search('subdistricts', ['district_id' => $district], $term) as $id => $label) {
                    $out[$id] ??= [$id, $label, $district];
                }
            }

            return array_values($out);
        }

        $found = match (true) {
            $level === 'cities' => DictionaryOptions::search('cities', [], $term),
            $level === 'districts' && $parents !== [] => DictionaryOptions::search('districts', ['city_id' => $parents[0]], $term),
            default => [],
        };

        return array_map(fn (int $id, string $label): array => [$id, $label], array_keys($found), array_values($found));
    }

    /**
     * «Готово» в панели места: одно обновление на всё место. Без города районов нет. Приходит из браузера, поэтому
     * принимает что угодно: мусор отбрасывается, а подрайоны чужих районов — тоже.
     */
    public function applyPlace(mixed $city = null, mixed $districts = [], mixed $subdistricts = []): void
    {
        $ids = fn (mixed $v): array => array_values(array_unique(array_map('intval', array_filter(
            is_array($v) ? $v : [],
            fn (mixed $i): bool => (is_int($i) || is_string($i)) && ctype_digit((string) $i) && (int) $i > 0,
        ))));

        $this->city_id = is_scalar($city) && ctype_digit((string) $city) && (int) $city > 0 ? (int) $city : null;
        $this->district_id = $this->city_id !== null ? $ids($districts) : [];

        $own = [];
        foreach (array_slice($this->district_id, 0, self::SUBDISTRICT_DISTRICTS) as $district) {
            $own += DictionaryOptions::options('subdistricts', ['district_id' => $district]);
        }
        $this->subdistrict_id = $this->district_id !== [] ? array_values(array_filter($ids($subdistricts), fn (int $s): bool => isset($own[$s]))) : [];
    }

    /** Смена города уносит районы и микрорайоны прежнего. */
    public function updatedCityId(): void
    {
        $this->district_id = [];
        $this->subdistrict_id = [];
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
        $this->keepModeAllowed();
    }

    /** «По базе» — только по своему направлению: сменили сделку или вернули чужую из сессии — назад к «Свежему». */
    private function keepModeAllowed(): void
    {
        if ($this->mode === 'base' && ! $this->baseAllowed()) {
            $this->mode = 'fresh';
        }
    }

    private function baseAllowed(): bool
    {
        return FindListing::inOwnDirections(Direction::tryFrom((string) ($this->deal ?? '')), OrgAccess::directions($this->viewer()));
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

    /**
     * Районы, выбранные целиком рядом с микрорайонами других районов: Real Estate такое отклоняет (422).
     *
     * @return list<string>
     */
    private function wholeDistrictsBeside(): array
    {
        return FindListing::wholeDistrictsBesideSubdistricts($this->city_id !== null ? [$this->city_id] : [], $this->district_id, $this->subdistrict_id);
    }

    public function submit(): void
    {
        $this->normalizeBedrooms();
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

        if (($names = $this->wholeDistrictsBeside()) !== []) {
            Notification::make()->warning()->title(__('gshome.search.district_whole_beside_subdistricts', ['district' => implode(', ', $names)]))->send();

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
        $this->normalizeBedrooms();
        $directions = OrgAccess::directions($this->viewer());
        $dictionary = fn (string $name, array $params = []): array => DictionaryOptions::options($name, $params);
        $cities = $dictionary('cities');
        $types = $dictionary('property-types');
        $districts = $this->city_id !== null ? $dictionary('districts', ['city_id' => $this->city_id]) : [];
        $place = $this->placeState($cities, $districts);
        $chips = [
            'price' => self::rangeLabel($this->price_usd_min, $this->price_usd_max, '$'),
            'area' => self::rangeLabel($this->area_min, $this->area_max, '', self::NBSP.__('gshome.quick_search.sqm')),
            'bedrooms' => $this->bedrooms !== []
                ? __('gshome.quick_search.bedrooms_short', ['list' => collect($this->bedrooms)->sort()->map(fn (string $n): string => $n === '5' ? '5+' : $n)->implode(', ')])
                : null,
        ];

        return view('livewire.quick-search', [
            'baseAllowed' => $this->baseAllowed(),
            'dealOptions' => count($directions) > 1
                ? collect($directions)->mapWithKeys(fn (Direction $d): array => [$d->value => $d->getLabel()])->all()
                : [],
            'types' => $types,
            'place' => $place,
            'placeLabel' => $this->placeLabel($place),
            'chips' => $chips,
            'pricePresets' => array_map(fn (int $v): array => [$v, __('gshome.quick_search.up_to', ['value' => '$'.self::number($v)])], self::PRICE_PRESETS[$this->deal === 'rent' ? 'rent' : 'sale']),
            'areaPresets' => array_map(fn (array $r): array => [$r[0], $r[1], (string) self::rangeLabel($r[0], $r[1])], self::AREA_PRESETS),
            'summary' => collect([$this->placeLabel($place, short: true), $types[$this->property_type_id] ?? null, $chips['price'], $chips['bedrooms']])->filter()->implode(' · '),
            // «По базе» знает один район и не знает микрорайонов (решение 27.10): место сужается до города/района.
            'basePlaceWider' => $this->mode === 'base' && (count($this->district_id) > 1 || $this->subdistrict_id !== []),
            'collectorsOffline' => $this->mode === 'fresh' && app(RealEstateClient::class)->collectorsOnline() === false,
        ]);
    }

    private static function number(int $v): string
    {
        return number_format($v, 0, '', self::NBSP);
    }

    /** «до $90 000», «от 50 м²», «50–90 м²» — подпись заполненного фильтра; пусто — null. */
    private static function rangeLabel(?int $min, ?int $max, string $prefix = '', string $suffix = ''): ?string
    {
        $one = fn (int $v): string => $prefix.self::number($v).$suffix;

        return match (true) {
            $min !== null && $max !== null => $prefix.self::number($min).'–'.self::number($max).$suffix,
            $max !== null => __('gshome.quick_search.up_to', ['value' => $one($max)]),
            $min !== null => __('gshome.quick_search.from', ['value' => $one($min)]),
            default => null,
        };
    }

    /**
     * Выбранное место с подписями — начальное состояние панели места.
     *
     * @param  array<int, string>  $cities
     * @param  array<int, string>  $districts
     * @return array{city: array{0: int, 1: string}|null, districts: list<array{0: int, 1: string}>, subdistricts: list<array{0: int, 1: string, 2: int}>}
     */
    private function placeState(array $cities, array $districts): array
    {
        $chosen = array_flip(array_map('intval', $this->subdistrict_id));
        $subdistricts = [];
        foreach (array_slice($this->district_id, 0, self::SUBDISTRICT_DISTRICTS) as $district) {
            foreach (array_intersect_key(DictionaryOptions::options('subdistricts', ['district_id' => (int) $district]), $chosen) as $id => $label) {
                $subdistricts[$id] ??= [$id, $label, (int) $district];
            }
        }

        return [
            'city' => $this->city_id !== null ? [$this->city_id, $cities[$this->city_id] ?? '#'.$this->city_id] : null,
            'districts' => array_map(fn (int $id): array => [$id, $districts[$id] ?? '#'.$id], array_map('intval', $this->district_id)),
            'subdistricts' => array_values($subdistricts),
        ];
    }

    /**
     * «Тбилиси · Ваке, Сабуртало»; кратко — только самое узкое место.
     *
     * @param  array{city: array{0: int, 1: string}|null, districts: list<array{0: int, 1: string}>, subdistricts: list<array{0: int, 1: string, 2: int}>}  $place
     */
    private function placeLabel(array $place, bool $short = false): ?string
    {
        // Район с выбранными подрайонами называют подрайоны, остальные районы — целиком (рядом с чужими подрайонами такой район поиск отклонит, см. submit).
        $covered = array_column($place['subdistricts'], 2);
        $narrow = collect($place['districts'])->reject(fn (array $d): bool => in_array($d[0], $covered, true))
            ->concat($place['subdistricts'])->map(fn (array $p): string => $p[1])->implode(', ');
        $city = $place['city'][1] ?? null;
        if ($short) {
            return $narrow !== '' ? $narrow : $city;
        }

        return collect([$city, $narrow])->filter()->implode(' · ') ?: null;
    }
}
