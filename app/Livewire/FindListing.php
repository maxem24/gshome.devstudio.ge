<?php

namespace App\Livewire;

use App\Access\OrgAccess;
use App\Crm\Exceptions\CrmForbidden;
use App\Enums\Direction;
use App\Filament\Pages\Listings;
use App\Filament\Pages\MySearches;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\RealEstateClient;
use App\Search\SiteSearches;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
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
            ->iconButton()
            ->labeledFrom('md')
            ->modalHeading(__('gshome.search.find'))
            ->modalWidth(Width::ThreeExtraLarge)
            ->schema(fn (): array => $this->fields())
            ->modalSubmitActionLabel(__('gshome.search.run'))
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('showInBase', ['base' => true])->label(__('gshome.search.show_in_base'))->color('primary'),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                if ($arguments['base'] ?? false) {
                    $this->redirect(Listings::getUrl(['filters' => self::baseFilters($data)]));

                    return;
                }
                $this->runSearch($data, $action);
            });
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
        $dictionary = fn (string $name, array $params = []): array => rescue(
            fn (): array => $client->dictionary($name, $params)->mapWithKeys(fn (array $r): array => [$r['id'] => RealEstateClient::title($r)])->all(),
            [],
            report: false,
        );
        $ids = fn (mixed $value): array => array_values(array_filter((array) $value, fn (mixed $v): bool => is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0));

        return [
            Select::make('deal')->label(__('gshome.search.deal'))
                ->options(collect($directions)->mapWithKeys(fn (Direction $d): array => [$d->value => $d->getLabel()])->all())
                ->placeholder(__('gshome.listings.all'))
                ->visible(count($directions) > 1),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                Select::make('city_id')->label(__('gshome.listings.city'))
                    ->options(fn (): array => $dictionary('cities'))->searchable()->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('district_id', []);
                        $set('subdistrict_id', []);
                    }),
                Select::make('property_type_id')->label(__('gshome.listings.property_type'))
                    ->options(fn (): array => $dictionary('property-types'))->searchable(),
                Select::make('district_id')->label(__('gshome.listings.district'))->multiple()->searchable()->live()
                    ->options(fn (Get $get): array => ($city = $ids([$get('city_id')])) !== [] ? $dictionary('districts', ['city_id' => (int) $city[0]]) : [])
                    ->afterStateUpdated(fn (Set $set) => $set('subdistrict_id', [])),
                Select::make('subdistrict_id')->label(__('gshome.search.subdistrict'))->multiple()->searchable()
                    ->options(function (Get $get) use ($ids, $dictionary): array {
                        $out = [];
                        // Подрайоны каждого выбранного района; пять районов — предел, чтобы не плодить запросы.
                        foreach (array_slice($ids($get('district_id')), 0, 5) as $district) {
                            $out += $dictionary('subdistricts', ['district_id' => (int) $district]);
                        }

                        return $out;
                    }),
                TextInput::make('price_usd_min')->label(__('gshome.listings.price_min'))->numeric()->minValue(0),
                TextInput::make('price_usd_max')->label(__('gshome.listings.price_max'))->numeric()->minValue(0),
                TextInput::make('area_min')->label(__('gshome.listings.area_min'))->numeric()->minValue(0),
                TextInput::make('area_max')->label(__('gshome.listings.area_max'))->numeric()->minValue(0),
            ]),
            Select::make('rooms')->label(__('gshome.listings.rooms'))->multiple()
                ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5+']),
            Text::make(__('gshome.search.ss_hint'))->color('gray')->size('sm'),
        ];
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
