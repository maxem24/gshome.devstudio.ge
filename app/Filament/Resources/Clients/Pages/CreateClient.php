<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Crm\ClientLookup;
use App\Crm\ClientMatch;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;

/**
 * Создание по номеру: new — создаём, same — открываем существующего,
 * conflict — спрашиваем («тот же человек» / «новый человек на этом номере»).
 */
class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $match = $this->match($data);

        if ($match->kind === 'new') {
            try {
                return ClientLookup::create($match, $this->attributes($data), $this->viewer());
            } catch (UniqueConstraintViolationException) {
                // Гонка: номер за это время занял другой агент — разбираем заново как same/conflict.
                $match = $this->match($data);
                if ($match->kind === 'new') {
                    // Конкурирующая запись откатилась — номер свободен, создаём ещё раз.
                    try {
                        return ClientLookup::create($match, $this->attributes($data), $this->viewer());
                    } catch (UniqueConstraintViolationException) {
                        $this->tryAgain();
                    }
                }
            }
        }

        $this->resolve($match);

        throw new Halt;
    }

    /** Окно конфликта; подтверждение пересчитывает совпадение по текущей форме. */
    public function resolveConflictAction(): Action
    {
        return Action::make('resolveConflict')
            ->modalHeading(__('gshome.crm.clients.conflict_title'))
            ->modalDescription(function (): string {
                $match = $this->match($this->form->getRawState());
                $existing = ClientLookup::hiddenFrom($match, $this->viewer()) ? null : $match->existing;

                return __('gshome.crm.clients.conflict_body', [
                    'name' => $existing === null ? '' : trim($existing->first_name.' '.$existing->last_name),
                ]);
            })
            ->modalSubmitActionLabel(__('gshome.crm.clients.new_person'))
            ->extraModalFooterActions([
                Action::make('samePerson')
                    ->label(__('gshome.crm.clients.same_person'))
                    ->color('gray')
                    ->cancelParentActions()
                    ->action(function (): void {
                        $match = $this->match($this->form->getState());
                        $this->openExisting($match);
                    }),
            ])
            ->action(function (): void {
                $data = $this->form->getState();
                $match = $this->match($data);
                if (ClientLookup::hiddenFrom($match, $this->viewer())) {
                    $this->openExisting($match);

                    return;
                }
                try {
                    if ($match->kind === 'conflict') {
                        $this->redirectTo(ClientLookup::reassignPhone($match, $this->attributes($data), $this->viewer()));

                        return;
                    }
                    // Пока читали окно, картина изменилась: new — создаём, same — открываем.
                    if ($match->kind === 'new') {
                        $this->redirectTo(ClientLookup::create($match, $this->attributes($data), $this->viewer()));

                        return;
                    }
                } catch (UniqueConstraintViolationException) {
                    $this->tryAgain();

                    return;
                }
                $this->openExisting($match);
            });
    }

    /** @param array<string, mixed> $data */
    protected function match(array $data): ClientMatch
    {
        return ClientLookup::match((string) $data['phone'], (string) $data['first_name'], $data['last_name'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'first_name' => $data['first_name'],
            'last_name' => filled($data['last_name'] ?? null) ? $data['last_name'] : null,
            'tags' => array_values($data['tags'] ?? []),
            'reliability' => $data['reliability'],
        ];
    }

    private function tryAgain(): void
    {
        Notification::make()->title(__('gshome.crm.clients.try_again'))->warning()->send();
        $this->halt();
    }

    private function resolve(ClientMatch $match): void
    {
        if (ClientLookup::hiddenFrom($match, $this->viewer())) {
            // Архивный клиент скрыт от смотрящего: ни имени, ни окна, ни переноса номера.
            $this->openExisting($match);
        } elseif ($match->kind === 'same') {
            $this->openExisting($match);
        } else {
            $this->mountAction('resolveConflict');
        }
    }

    private function openExisting(ClientMatch $match): void
    {
        $existing = $match->existing;
        if ($existing === null) {
            return;
        }

        // Архивного клиента агент открыть не может: номер занят, но карточка скрыта.
        if (ClientLookup::hiddenFrom($match, $this->viewer())) {
            Notification::make()->title(__('gshome.crm.clients.archived_exists'))->warning()->send();

            return;
        }

        Notification::make()->title(__('gshome.crm.clients.already_exists'))->info()->send();
        $this->redirectTo($existing);
    }

    private function redirectTo(Client $client): void
    {
        $this->redirect(ClientResource::getUrl('view', ['record' => $client]));
    }

    private function viewer(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
