<?php

namespace App\Filament\Pages\Concerns;

use App\Crm\Exceptions\CrmForbidden;
use App\Filament\Pages\MySearches;
use App\Models\ListingSearch;
use App\Models\User;
use App\RealEstate\Exceptions\RealEstateBadRequest;
use App\RealEstate\Exceptions\RealEstateException;
use App\RealEstate\Exceptions\RealEstateRateLimited;
use App\RealEstate\LinkImport;
use App\Search\LinkImports;
use App\Search\SiteSearches;
use Filament\Notifications\Notification;

/** «Повторить» поиск: страница «Мои поиски» и блок закреплённых поисков на инфопанели. */
trait RepeatsSearches
{
    abstract protected function viewer(): User;

    public function searchAgain(int $id): void
    {
        $viewer = $this->viewer();
        $search = ListingSearch::query()->visibleTo($viewer)->findOrFail($id);
        abort_unless($search->user_id === $viewer->getKey(), 403);

        try {
            // Поиск по ссылке повторяется той же ссылкой: фильтров формы у него нет.
            $link = $search->filters['link_sent'] ?? $search->filters['link'] ?? null;
            if (is_string($link) && $link !== '') {
                $linkStart = app(LinkImports::class)->start($viewer, $link);
                if ($linkStart->listing !== null) {
                    // Ссылка вдруг стала объявлением (сайт поменял адреса): ведём себя как «По ссылке».
                    $this->openImportedListing($viewer, $linkStart->listing);

                    return;
                }
                $start = $linkStart->search;
            } else {
                $start = app(SiteSearches::class)->start($viewer, $search->filters);
            }
        } catch (CrmForbidden) {
            abort(403);
        } catch (RealEstateRateLimited) {
            Notification::make()->danger()->title(__('gshome.search.rate_limited'))->send();

            return;
        } catch (RealEstateBadRequest) {
            Notification::make()->danger()->title(__('gshome.link.rejected'))->send();

            return;
        } catch (RealEstateException $e) {
            Notification::make()->danger()->title($e->userMessage())->send();

            return;
        }

        if ($start === null) {
            return; // LinkStart без объявления всегда с поиском; ветка — для анализатора
        }
        MySearches::notifyStart($start);
    }

    private function openImportedListing(User $viewer, LinkImport $listing): void
    {
        if ($listing->ready()) {
            $this->redirect(LinkImports::cardUrl($viewer, (int) $listing->listingId));
        } elseif ($listing->finished()) {
            Notification::make()->danger()->title($listing->message !== '' ? $listing->message : __('gshome.link.failed'))->send();
        } else {
            Notification::make()->success()->title(__('gshome.link.queued'))->body(__('gshome.link.queued_body'))->send();
        }
    }
}
