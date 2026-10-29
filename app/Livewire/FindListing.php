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
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Кнопка «Найти квартиру» в шапке: окно с фильтрами и два пути —
 * «Показать в базе» (экран объектов с этими фильтрами) и «Искать на сайтах» (фоновый поиск, результат — в колокольчик).
 */
class FindListing extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function findAction(): Action
    {
        return Action::make('find')
            ->label(__('gshome.search.find'))
            ->icon(Heroicon::MagnifyingGlass)
            ->color('gray')
            ->labeledFrom('md')
            ->modalHeading(__('gshome.search.find'))
            ->modalWidth(Width::ThreeExtraLarge)
            ->schema(fn (): array => $this->fields())
            ->modalSubmitActionLabel(__('gshome.search.run'))
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('showInBase', ['base' => true])->label(__('gshome.search.show_in_base'))->color('primary')
                    ->disabled(function (): bool {
                        $own = OrgAccess::directions($this->viewer());
                        $state = self::normalizeDeal((array) ($this->mountedActions[0]['data'] ?? []), $own);

                        return ! self::inOwnDirections(Direction::tryFrom((string) ($state['deal'] ?? '')), $own);
                    }),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $data = self::normalizeDeal($data, OrgAccess::directions($this->viewer()));
                if ($arguments['base'] ?? false) {
                    if (! self::inOwnDirections(Direction::tryFrom((string) ($data['deal'] ?? '')), OrgAccess::directions($this->viewer()))) {
                        Notification::make()->warning()->title(__('gshome.search.base_own_only'))->send();
                        $action->halt();
                    }
                    $this->redirect(Listings::getUrl(['filters' => self::baseFilters($data)]));

                    return;
                }
                $this->runSearch($data, $action);
            });
    }

    /**
     * Переключатель «другое направление» → явная сделка в фильтрах; сам переключатель дальше не идёт.
     *
     * @param  array<string, mixed>  $data
     * @param  list<Direction>  $directions
     * @return array<string, mixed>
     */
    public static function normalizeDeal(array $data, array $directions): array
    {
        if (! empty($data['other_direction']) && ($other = self::otherDirection($directions)) !== null) {
            $data['deal'] = $other->value;
        } elseif (blank($data['deal'] ?? null) && count($directions) === 1) {
            $data['deal'] = $directions[0]->value;
        }
        unset($data['other_direction']);

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private function runSearch(array $data, Action $action): void
    {
        $user = $this->viewer();
        // Права проверяем на сервере: форма могла быть подделана.
        try {
            $start = app(SiteSearches::class)->start($user, $data);
        } catch (CrmForbidden) {
            abort(403);
        } catch (RealEstateRateLimited) {
            $this->fail(__('gshome.search.rate_limited'), $action);

            return;
        } catch (RealEstateException $e) {
            $this->fail($e->userMessage(), $action);

            return;
        }

        MySearches::notifyStart($start);
    }

    /** Окно не закрываем: человек поправит поиск и повторит. */
    private function fail(string $message, Action $action): void
    {
        Notification::make()->danger()->title($message)->send();
        $action->halt();
    }

    /**
     * Форма поиска → `filters` экрана «Объекты» (те же имена, что у его фильтров).
     * Экран знает один район и не знает подрайонов: район передаём, только если он один.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function baseFilters(array $data): array
    {
        $filters = [];
        $set = function (string $path, mixed $value) use (&$filters): void {
            if (filled($value)) {
                data_set($filters, $path, is_array($value) ? $value : (string) $value);
            }
        };

        $set('direction.value', $data['deal'] ?? null);
        $set('price.min', $data['price_usd_min'] ?? null);
        $set('price.max', $data['price_usd_max'] ?? null);
        $set('area.min', $data['area_min'] ?? null);
        $set('area.max', $data['area_max'] ?? null);
        $set('property_type.value', $data['property_type_id'] ?? null);
        $set('location.city_id', $data['city_id'] ?? null);

        $districts = array_values(array_filter((array) ($data['district_id'] ?? [])));
        if (count($districts) === 1) {
            $set('location.district_id', $districts[0]);
        }

        // Экран знает «5+»: пятая и дальше комнаты — одно значение.
        $rooms = array_values(array_unique(array_map(
            fn (mixed $r): string => (int) $r >= 5 ? '5+' : (string) (int) $r,
            array_filter((array) ($data['rooms'] ?? [])),
        )));
        $set('rooms.values', $rooms);

        return $filters;
    }

    /** @return array<int, mixed> */
    private function fields(): array
    {
        $client = app(RealEstateClient::class);
        $directions = OrgAccess::directions($this->viewer());
        $dictionary = fn (string $name, array $params = []): array => DictionaryOptions::options($name, $params);
        $ids = fn (mixed $value): array => array_values(array_filter((array) $value, fn (mixed $v): bool => is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0));

        return [
            Select::make('deal')->label(__('gshome.search.deal'))
                ->options(fn (Get $get): array => collect($get('other_direction') ? Direction::cases() : $directions)->mapWithKeys(fn (Direction $d): array => [$d->value => $d->getLabel()])->all())
                ->default(count($directions) === 1 ? $directions[0]->value : null)
                ->placeholder(__('gshome.listings.all'))
                ->selectablePlaceholder(count($directions) > 1)
                ->live()
                ->disabled(fn (Get $get): bool => count($directions) === 1 && (bool) $get('other_direction')),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                Select::make('city_id')->label(__('gshome.listings.city'))
                    ->options(fn (): array => $dictionary('cities'))->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search): array => DictionaryOptions::search('cities', [], $search))
                    ->afterStateUpdated(function (Set $set): void {
                        $set('district_id', []);
                        $set('subdistrict_id', []);
                    }),
                Select::make('property_type_id')->label(__('gshome.listings.property_type'))
                    ->options(fn (): array => $dictionary('property-types'))->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => DictionaryOptions::search('property-types', [], $search)),
                Select::make('district_id')->label(__('gshome.listings.district'))->multiple()->searchable()->live()
                    ->options(fn (Get $get): array => ($city = $ids([$get('city_id')])) !== [] ? $dictionary('districts', ['city_id' => (int) $city[0]]) : [])
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => ($city = $ids([$get('city_id')])) !== [] ? DictionaryOptions::search('districts', ['city_id' => (int) $city[0]], $search) : [])
                    // Снятый район уносит только свои микрорайоны: остальные выбранные остаются.
                    ->afterStateUpdated(fn (Set $set, Get $get) => $set('subdistrict_id', array_keys(array_intersect_key(
                        self::subdistrictOptions($ids($get('district_id'))),
                        array_flip(array_map('intval', $ids($get('subdistrict_id')))),
                    ))))
                    ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $ids): void {
                        foreach (self::wholeDistrictsBesideSubdistricts($ids($get('city_id')), $ids($value), $ids($get('subdistrict_id'))) as $name) {
                            $fail(__('gshome.search.district_whole_beside_subdistricts', ['district' => $name]));
                        }
                    }]),
                Select::make('subdistrict_id')->label(__('gshome.search.subdistrict'))->multiple()->searchable()
                    ->getSearchResultsUsing(function (string $search, Get $get) use ($ids): array {
                        $out = [];
                        foreach (array_slice($ids($get('district_id')), 0, 5) as $district) {
                            $out += DictionaryOptions::search('subdistricts', ['district_id' => (int) $district], $search);
                        }

                        return $out;
                    })
                    // Подрайоны каждого выбранного района; пять районов — предел, чтобы не плодить запросы.
                    ->options(fn (Get $get): array => self::subdistrictOptions(array_slice($ids($get('district_id')), 0, 5))),
                TextInput::make('price_usd_min')->label(__('gshome.listings.price_min'))->numeric()->minValue(0)->live(onBlur: true),
                TextInput::make('price_usd_max')->label(__('gshome.listings.price_max'))->numeric()->minValue(0)->live(onBlur: true),
                Text::make(fn (Get $get): string => __('gshome.search.low_sale_price', ['price' => number_format(self::lowSalePrice($get, $directions) ?? 0)]))
                    ->key('low_price_warning')->color('warning')->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::lowSalePrice($get, $directions) !== null),
                TextInput::make('area_min')->label(__('gshome.listings.area_min'))->numeric()->minValue(0),
                TextInput::make('area_max')->label(__('gshome.listings.area_max'))->numeric()->minValue(0),
            ]),
            Select::make('rooms')->label(__('gshome.listings.rooms'))->multiple()
                ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5+']),
            Section::make(__('gshome.search.extra_filters'))->collapsed()->compact()->schema([
                Toggle::make('other_direction')->label(__('gshome.search.other_direction', ['direction' => self::otherDirection($directions)?->getLabel()]))
                    ->live()
                    ->afterStateUpdated(fn (Set $set, bool $state) => $set('deal', ($state ? self::otherDirection($directions) : $directions[0])?->value))
                    ->visible(self::otherDirection($directions) !== null),
            ])->visible(self::otherDirection($directions) !== null),
            Text::make(__('gshome.search.base_own_only'))->key('base_hint')->color('warning')
                ->visible(fn (Get $get): bool => ! self::inOwnDirections(self::effectiveDeal($get, $directions), $directions)),
            Text::make(__('gshome.search.ss_hint'))->color('gray')->size('sm'),
        ];
    }

    /**
     * Микрорайоны выбранных районов, по районам: id района ⇒ [id микрорайона ⇒ подпись].
     *
     * @param  list<int|string>  $districts
     * @return Collection<int, array<int, string>>
     */
    private static function subdistrictsByDistrict(array $districts): Collection
    {
        return collect($districts)->mapWithKeys(fn (int|string $district): array => [
            (int) $district => DictionaryOptions::options('subdistricts', ['district_id' => (int) $district]),
        ]);
    }

    /**
     * Все микрорайоны выбранных районов одним списком; ключи — id, их не перенумеровываем.
     *
     * @param  list<int|string>  $districts
     * @return array<int, string>
     */
    private static function subdistrictOptions(array $districts): array
    {
        $out = [];
        foreach (self::subdistrictsByDistrict($districts) as $options) {
            $out += $options;
        }

        return $out;
    }

    /**
     * Сервер ищет по району И микрорайону сразу (с 29.10). Район без выбранных микрорайонов рядом
     * с микрорайонами другого района ничего не найдёт, API ответит 422. Такие районы — подписями.
     *
     * @param  list<int|string>  $city
     * @param  list<int|string>  $districts
     * @param  list<int|string>  $subdistricts
     * @return list<string>
     */
    public static function wholeDistrictsBesideSubdistricts(array $city, array $districts, array $subdistricts): array
    {
        if ($subdistricts === [] || count($districts) < 2) {
            return [];
        }
        $chosen = array_flip(array_map('intval', $subdistricts));
        $labels = $city !== [] ? DictionaryOptions::labels('districts', ['city_id' => (int) $city[0]], $districts) : [];

        return self::subdistrictsByDistrict($districts)
            ->filter(fn (array $options): bool => array_intersect_key($options, $chosen) === [])
            ->keys()
            ->map(fn (int $district): string => $labels[$district] ?? (string) $district)
            ->values()
            ->all();
    }

    /**
     * Куда реально пойдёт поиск: «другое направление» (только у людей с одним направлением) перекрывает поле.
     *
     * @param  list<Direction>  $directions
     */
    private static function effectiveDeal(Get $get, array $directions): ?Direction
    {
        if (count($directions) === 1) {
            return $get('other_direction') ? self::otherDirection($directions) : $directions[0];
        }

        return filled($get('deal')) ? Direction::tryFrom((string) $get('deal')) : null;
    }

    /**
     * Человеку с одним направлением — противоположное; у кого оба, «другого» нет.
     *
     * @param  list<Direction>  $directions
     */
    private static function otherDirection(array $directions): ?Direction
    {
        return count($directions) === 1 ? ($directions[0] === Direction::Sale ? Direction::Rent : Direction::Sale) : null;
    }

    /** @param  list<Direction>  $directions */
    private static function inOwnDirections(?Direction $deal, array $directions): bool
    {
        return $deal === null || in_array($deal, $directions, true);
    }

    /**
     * Продажа дешевле $10 000 почти всегда ошибка (цену ввели в тысячах или это аренда).
     * Возвращает подозрительную сумму или null, если предупреждать не о чем.
     *
     * @param  list<Direction>  $directions
     */
    private static function lowSalePrice(Get $get, array $directions): ?float
    {
        if (self::effectiveDeal($get, $directions) !== Direction::Sale) {
            return null;
        }
        foreach (['price_usd_max', 'price_usd_min'] as $key) {
            $value = $get($key);
            if (is_numeric($value) && (float) $value > 0 && (float) $value < 10000) {
                return (float) $value;
            }
        }

        return null;
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.find-listing');
    }
}
