<?php

namespace App\Livewire;

use App\Access\OrgAccess;
use App\Crm\Exceptions\CrmForbidden;
use App\Filament\Pages\MySearches;
use App\Filament\Pages\SearchResult;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateBadRequest;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\RealEstateClient;
use App\Search\LinkImports;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Кнопка «По ссылке» в шапке рядом с «Найти квартиру»: ссылка myhome / ss.ge на объявление или на выдачу.
 * Объявление открывается карточкой (или приходит в колокольчик, когда сборщик его откроет), выдача — поиском.
 * Видна, только когда Real Estate принимает ссылки (`GET /me` → `imports`).
 */
class ImportByLink extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public function linkAction(): Action
    {
        return Action::make('link')
            ->label(__('gshome.link.button'))
            ->icon(Heroicon::Link)
            ->color('gray')
            ->labeledFrom('md')
            ->visible(fn (): bool => OrgAccess::directions($this->viewer()) !== [] && app(RealEstateClient::class)->importSources() !== [])
            ->modalHeading(__('gshome.link.heading'))
            ->modalWidth(Width::TwoExtraLarge)
            ->schema([
                // Textarea: длинная выдача с фильтрами сайта в одну строку не видна целиком.
                Textarea::make('url')->label(__('gshome.link.url'))->required()->rows(3)->autofocus()
                    ->placeholder('https://www.myhome.ge/… · https://home.ss.ge/…')
                    ->maxLength(LinkImports::MAX_LENGTH)
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (! is_string($value) || LinkImports::source($value) === null) {
                            $fail(__('gshome.link.wrong_site'));
                        }
                    }]),
                Text::make(__('gshome.link.hint')),
            ])
            ->modalSubmitActionLabel(__('gshome.link.run'))
            ->action(fn (array $data, Action $action) => $this->import((string) $data['url'], $action));
    }

    private function import(string $url, Action $action): void
    {
        try {
            $start = app(LinkImports::class)->start($this->viewer(), $url);
        } catch (CrmForbidden) {
            abort(403);
        } catch (RealEstateRateLimited) {
            $this->fail(__('gshome.search.rate_limited'), $action);

            return;
        } catch (RealEstateBadRequest) {
            // Общий текст «не удалось загрузить объявления» про ссылку ничего не говорит.
            $this->fail(__('gshome.link.rejected'), $action);

            return;
        } catch (RealEstateException $e) {
            $this->fail($e->userMessage(), $action);

            return;
        }

        if ($start->search !== null) {
            MySearches::notifyStart($start->search);
            $this->redirect(SearchResult::getUrl(['search' => $start->search->search->getKey()]));

            return;
        }

        $listing = $start->listing;
        if ($listing?->ready()) {
            $this->redirect(LinkImports::cardUrl($this->viewer(), $listing->listingId));

            return;
        }
        if ($listing !== null && $listing->finished()) {
            // Сразу отказ (снято, сайт на паузе): окно оставляем, ссылку можно поправить.
            $this->fail($listing->message !== '' ? $listing->message : __('gshome.link.failed'), $action);

            return;
        }

        Notification::make()->success()->title(__('gshome.link.queued'))->body(__('gshome.link.queued_body'))->send();
    }

    /** Окно не закрываем: человек поправит ссылку и повторит. */
    private function fail(string $message, Action $action): void
    {
        Notification::make()->danger()->title($message)->send();
        $action->halt();
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.import-by-link');
    }
}
