<?php

namespace App\Filament\Pages\Concerns;

use App\Crm\ClientLookup;
use App\Crm\ClientMatch;
use App\Crm\Deals;
use App\Crm\Exceptions\CrmForbidden;
use App\Crm\Pins;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\ListingPin;
use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Locked;

/**
 * «Открыть сделку»: клиент по телефону — new создаём, same берём, conflict спрашиваем
 * (тот же выбор, что при создании клиента в разделе «Клиенты»).
 * Нужны на странице: dealPin(): ?ListingPin, viewer(): User, afterDealChange().
 */
trait OpensDealsByPhone
{
    /** Введённое в окне «Открыть сделку», пока человек отвечает на вопрос о конфликте. */
    #[Locked]
    public array $dealDraft = [];

    abstract protected function dealPin(): ?ListingPin;

    abstract protected function afterDealChange(): void;

    protected function openDealAction(): Action
    {
        return Action::make('openDeal')
            ->label(__('gshome.crm.pins.open_deal'))
            ->icon(Heroicon::OutlinedBriefcase)
            ->visible(fn (): bool => $this->canManageDealPin())
            ->modalHeading(__('gshome.crm.pins.open_deal'))
            ->schema([
                TextInput::make('phone')
                    ->label(__('gshome.crm.clients.phone'))->tel()->required()->maxLength(32)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (PhoneNumber::tryNormalize((string) $value) === null) {
                            $fail(__('gshome.crm.clients.phone_invalid'));
                        }
                    }),
                TextInput::make('first_name')->label(__('gshome.crm.clients.first_name'))->required()->maxLength(255),
                TextInput::make('last_name')->label(__('gshome.crm.clients.last_name'))->maxLength(255),
            ])
            ->action(function (array $data): void {
                $match = $this->matchClient($data);
                if ($match->kind === 'conflict') {
                    $this->askConflict($data);

                    throw new Halt;
                }
                $this->openDealFor($match, $data);
            });
    }

    /** Окно конфликта: подтверждение пересчитывает совпадение заново. */
    public function resolveDealConflictAction(): Action
    {
        return Action::make('resolveDealConflict')
            ->modalHeading(__('gshome.crm.clients.conflict_title'))
            ->modalDescription(function (): string {
                $existing = $this->matchClient($this->dealDraft)->existing;

                return __('gshome.crm.clients.conflict_body', [
                    'name' => $existing === null ? '' : trim($existing->first_name.' '.$existing->last_name),
                ]);
            })
            ->modalSubmitActionLabel(__('gshome.crm.clients.new_person'))
            ->extraModalFooterActions([
                Action::make('samePersonDeal')
                    ->label(__('gshome.crm.clients.same_person'))
                    ->color('gray')
                    ->cancelParentActions()
                    ->action(function (): void {
                        $data = $this->dealDraft;
                        $match = $this->matchClient($data);
                        if ($match->existing === null) {
                            $this->openDealFor($match, $data);

                            return;
                        }
                        $this->dealOn($match->existing);
                    }),
            ])
            ->action(function (): void {
                $data = $this->dealDraft;
                $match = $this->matchClient($data);
                if ($match->kind === 'conflict') {
                    $this->dealOn(ClientLookup::reassignPhone($match, $this->clientAttributes($data), $this->viewer()));

                    return;
                }
                $this->openDealFor($match, $data);
            });
    }

    /** @param array<string, mixed> $data */
    private function askConflict(array $data): void
    {
        $this->dealDraft = $data;
        $this->replaceMountedAction('resolveDealConflict');
    }

    /** @param array<string, mixed> $data */
    private function matchClient(array $data): ClientMatch
    {
        return ClientLookup::match((string) ($data['phone'] ?? ''), (string) ($data['first_name'] ?? ''), $data['last_name'] ?? null);
    }

    /** @param array<string, mixed> $data */
    private function openDealFor(ClientMatch $match, array $data): void
    {
        if ($match->kind === 'new') {
            try {
                $client = ClientLookup::create($match, $this->clientAttributes($data), $this->viewer());
            } catch (UniqueConstraintViolationException) {
                // Гонка: номер занял другой агент — разбираем заново.
                $again = $this->matchClient($data);
                if ($again->kind === 'new') {
                    $client = ClientLookup::create($again, $this->clientAttributes($data), $this->viewer());
                } else {
                    $this->openDealFor($again, $data);

                    return;
                }
            }
            $this->dealOn($client);

            return;
        }

        if ($match->kind === 'conflict' || $match->existing === null) {
            // Пока читали окно, картина изменилась на конфликт: спросим ещё раз.
            $this->askConflict($data);

            return;
        }

        $this->dealOn($match->existing);
    }

    private function dealOn(Client $client): void
    {
        // Архивного клиента агент открыть не может: номер занят, но карточка скрыта.
        if ($client->trashed() && ! ClientResource::getEloquentQuery()->whereKey($client->getKey())->exists()) {
            Notification::make()->title(__('gshome.crm.clients.archived_exists'))->warning()->send();

            return;
        }

        $pin = $this->dealPin();
        try {
            if ($pin === null) {
                throw new CrmForbidden('Pin is gone.');
            }
            app(Deals::class)->open($pin, $client, $this->viewer());
        } catch (CrmForbidden) {
            Notification::make()->title(__('gshome.crm.pins.deal_forbidden'))->danger()->send();
            $this->afterDealChange();

            return;
        }

        Notification::make()->title(__('gshome.crm.pins.deal_opened'))->success()->send();
        $this->afterDealChange();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clientAttributes(array $data): array
    {
        return [
            'first_name' => $data['first_name'],
            'last_name' => filled($data['last_name'] ?? null) ? $data['last_name'] : null,
            'tags' => [],
            'reliability' => 'unverified',
        ];
    }

    private function canManageDealPin(): bool
    {
        $pin = $this->dealPin();

        return $pin !== null && app(Pins::class)->canManage($pin, $this->viewer());
    }

    abstract protected function viewer(): User;
}
