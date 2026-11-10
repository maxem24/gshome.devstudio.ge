<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Access\OrgAccess;
use App\Crm\DealIntake;
use App\Crm\Deals;
use App\Crm\DealStage;
use App\Crm\DealStages;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Journal;
use App\Enums\Direction;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ViewDeal extends ViewRecord
{
    protected static string $resource = DealResource::class;

    /** Булевы ответы анкеты: в форме — выбор «не спрашивали / да / нет», а не переключатель. */
    private const TRI_STATE = ['pets', 'bank_help', 'wants_discount'];

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Deal ? __('gshome.crm.deals.title', ['id' => $record->id]) : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('nextStage')
                ->label(function (Deal $record): string {
                    $next = $this->nextStage($record);

                    return __('gshome.crm.deals.next_stage').($next === null ? '' : ': '.$next->label());
                })
                ->visible(fn (Deal $record): bool => $record->isOpen() && $this->can('manage', $record) && $this->nextStage($record) !== null)
                ->action(fn (Deal $record) => $this->perform($record, 'manage', function (Deal $deal, User $user): void {
                    // Следующий этап считается на сервере, а не берётся из запроса.
                    $next = $this->nextStage($deal);
                    if ($next === null) {
                        throw new CrmForbidden('No next stage.');
                    }
                    app(Deals::class)->moveStage($deal, $next->key, $user);
                })),
            Action::make('win')
                ->label(__('gshome.crm.deals.won'))
                ->color('success')
                ->visible(fn (Deal $record): bool => $record->isOpen() && $this->can('manage', $record))
                ->schema([
                    TextInput::make('amount')
                        ->label(__('gshome.crm.deals.commission'))
                        ->required()
                        ->regex('/^\d{1,12}(\.\d{1,2})?$/')
                        ->inputMode('decimal'),
                    Select::make('currency')
                        ->label(__('gshome.crm.deals.currency'))
                        ->options(array_combine((array) config('crm.currencies'), (array) config('crm.currencies')))
                        ->default(config('crm.currencies.0'))
                        ->required(),
                    // Доля партнёра — суммой из комиссии; поле только у сделки с партнёром.
                    TextInput::make('partner_share')
                        ->label(__('gshome.crm.deals.partner_share'))
                        ->helperText(__('gshome.crm.deals.partner_share_hint'))
                        ->visible(fn (Deal $record): bool => $record->partner_user_id !== null)
                        ->required()
                        ->regex('/^\d{1,12}(\.\d{1,2})?$/')
                        ->inputMode('decimal'),
                ])
                ->action(fn (Deal $record, array $data) => $this->perform($record, 'manage', fn (Deal $deal, User $user) => app(Deals::class)->win(
                    $deal, (string) $data['amount'], (string) $data['currency'], $user,
                    // Есть ли партнёр, решает сервер по сделке, а не форма.
                    $deal->partner_user_id === null ? null : (string) ($data['partner_share'] ?? ''),
                ))),
            Action::make('editIntake')
                ->label(__('gshome.crm.intake.edit'))
                ->visible(fn (Deal $record): bool => $record->isOpen() && $this->can('manage', $record))
                ->fillForm(fn (Deal $record): array => [
                    ...array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : $v, $record->intake ?? []),
                    'price' => $record->price,
                    'price_currency' => $record->price_currency,
                ])
                ->schema(fn (Deal $record): array => self::intakeSchema($record->pin->direction))
                ->action(fn (Deal $record, array $data) => $this->perform($record, 'manage', function (Deal $deal, User $user) use ($data): void {
                    $intake = Arr::only($data, DealIntake::fields($deal->pin->direction));
                    // Да / нет / не спрашивали: пустое не пишется, «нет» — это false.
                    foreach (self::TRI_STATE as $key) {
                        if (array_key_exists($key, $intake)) {
                            $intake[$key] = match ($intake[$key]) {
                                'yes' => true,
                                'no' => false,
                                default => null,
                            };
                        }
                    }
                    $price = filled($data['price'] ?? null) ? (string) $data['price'] : null;
                    // Валюта без цены не сохраняется: в базе они идут парой.
                    $currency = $price === null ? null : (string) ($data['price_currency'] ?? '');
                    app(Deals::class)->updateIntake($deal, $intake, $price, $currency, $user);
                })),
            Action::make('setPartner')
                ->label(__('gshome.crm.deals.set_partner'))
                ->color('gray')
                ->visible(fn (Deal $record): bool => $record->isOpen() && $this->can('manage', $record))
                ->fillForm(fn (Deal $record): array => ['partner_user_id' => $record->partner_user_id])
                ->schema(fn (Deal $record): array => [
                    Select::make('partner_user_id')
                        ->label(__('gshome.crm.deals.partner'))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::partnerCandidates($record->agent, $search))
                        // Имя — только текущего партнёра или допустимого кандидата, а не любого id.
                        ->getOptionLabelUsing(fn ($value): ?string => self::partnerLabel($record, $value)),
                ])
                ->action(fn (Deal $record, array $data) => $this->perform($record, 'manage', function (Deal $deal, User $user) use ($data): void {
                    $partner = filled($data['partner_user_id'] ?? null) ? User::query()->find($data['partner_user_id']) : null;
                    // Чужого или неподходящего человека отвергает сам сервис (CrmForbidden).
                    app(Deals::class)->setPartner($deal, $partner, $user);
                })),
            Action::make('lose')
                ->label(__('gshome.crm.deals.lost'))
                ->color('danger')
                ->visible(fn (Deal $record): bool => $record->isOpen() && $this->can('manage', $record))
                ->schema([
                    Textarea::make('reason')->label(__('gshome.crm.deals.reason'))->required()->maxLength(1000),
                ])
                ->action(fn (Deal $record, array $data) => $this->perform($record, 'manage', fn (Deal $deal, User $user) => app(Deals::class)->lose(
                    $deal, (string) $data['reason'], $user,
                ))),
            Action::make('archive')
                ->label(__('gshome.crm.clients.to_archive'))
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (Deal $record): bool => $this->can('archive', $record))
                ->action(function (Deal $record): void {
                    $this->authorizeFor('archive', $record);
                    DB::transaction(function () use ($record): void {
                        $record->delete();
                        Journal::record(Journal::ARCHIVED, $record);
                    });
                    // Не владелец после архивации сделку уже не видит.
                    $this->redirect(DealResource::getUrl('index'));
                }),
        ];
    }

    /**
     * Кандидаты в партнёры: активные люди с командой из круга компаний агента сделки.
     *
     * @return array<int, string>
     */
    public static function partnerCandidates(User $agent, string $search): array
    {
        /** @var array<int, string> $found */
        $found = User::query()
            ->whereIn('company_id', OrgAccess::identifiableCompanyIds($agent))
            ->where('status', UserStatus::Active)
            ->whereIn('role', [Role::TeamLead, Role::Agent])
            ->whereKeyNot($agent->id)
            ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->filter(fn (User $u): bool => OrgAccess::canPartner($agent, $u))
            ->pluck('name', 'id')
            ->all();

        return $found;
    }

    /** Имя — только текущего партнёра или допустимого кандидата, а не любого id. */
    public static function partnerLabel(Deal $deal, mixed $id): ?string
    {
        $user = is_scalar($id) ? User::query()->find($id) : null;

        return $user !== null && ($user->id === $deal->partner_user_id || OrgAccess::canPartner($deal->agent, $user)) ? $user->name : null;
    }

    private static function triState(string $key, string $label): Select
    {
        return Select::make($key)->label($label)->options([
            'yes' => DealIntake::label($key, true),
            'no' => DealIntake::label($key, false),
        ]);
    }

    /** @return list<Field> поля анкеты направления и цена */
    public static function intakeSchema(Direction $direction): array
    {
        $currencies = array_combine((array) config('crm.currencies'), (array) config('crm.currencies'));
        $fields = [];
        foreach (DealIntake::fields($direction) as $key) {
            $label = DealIntake::label($key);
            $options = DealIntake::options($key);
            $ifMortgage = fn (Get $get): bool => $get('payment') === 'mortgage';
            $fields[] = match (true) {
                $key === 'down_payment_currency' => Select::make($key)->label($label)->options($currencies)->visible($ifMortgage),
                $key === 'down_payment' => TextInput::make($key)->label($label)->regex('/^\d{1,12}(\.\d{1,2})?$/')->inputMode('decimal')->visible($ifMortgage),
                $key === 'bank_help' => self::triState($key, $label)->visible(fn (Get $get): bool => $get('payment') === 'cash'),
                $key === 'notes' => Textarea::make($key)->label($label)->maxLength(2000)->columnSpanFull(),
                $options !== null => Select::make($key)
                    ->label($label)
                    ->options(collect($options)->mapWithKeys(fn (string $v): array => [$v => DealIntake::label($key, $v)])->all())
                    ->live($key === 'payment'),
                in_array($key, self::TRI_STATE, true) => self::triState($key, $label),
                default => TextInput::make($key)->label($label)->maxLength(255),
            };
        }

        return [
            ...$fields,
            TextInput::make('price')
                ->label(__($direction === Direction::Rent ? 'gshome.crm.deals.price_monthly' : 'gshome.crm.deals.price'))
                ->regex('/^\d{1,12}(\.\d{1,2})?$/')
                ->inputMode('decimal'),
            Select::make('price_currency')
                ->label(__('gshome.crm.deals.currency'))
                ->options($currencies)
                ->required(fn (Get $get): bool => filled($get('price'))),
        ];
    }

    /** Первый видимый обычный этап после текущего. */
    private function nextStage(Deal $deal): ?DealStage
    {
        $current = DealStages::find($deal->stage);
        foreach (DealStages::visible() as $stage) {
            if ($stage->kind === 'normal' && ($current === null || $stage->order > $current->order)) {
                return $stage;
            }
        }

        return null;
    }

    private function can(string $ability, Deal $deal): bool
    {
        return Auth::user()?->can($ability, $deal) ?? false;
    }

    /** Скрытая кнопка — не защита: каждое действие проверяет право заново. */
    private function authorizeFor(string $ability, Deal $deal): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can($ability, $deal), 403);

        return $user;
    }

    /** @param  Closure(Deal, User): mixed  $do */
    private function perform(Deal $deal, string $ability, Closure $do): void
    {
        $user = $this->authorizeFor($ability, $deal);
        try {
            $do($deal, $user);
            Notification::make()->title(__('gshome.crm.pins.closed'))->success()->send();
        } catch (CrmForbidden) {
            Notification::make()->title(__('gshome.crm.deals.forbidden'))->danger()->send();
        }
        $deal->refresh()->unsetRelations();
    }
}
