<?php

namespace App\Livewire;

use App\Models\ListingSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** Три последних поиска человека под быстрым поиском на главной. Пока хоть один ищется — опрашивает сервер раз в 10 секунд. */
final class RecentSearches extends Component
{
    /** @return Collection<int, ListingSearch> */
    public function searches(): Collection
    {
        $id = Auth::id();
        if ($id === null) {
            return new Collection;
        }

        return ListingSearch::query()->with('user')->where('user_id', $id)->latest('id')->limit(3)->get();
    }

    public function render(): View
    {
        $searches = $this->searches();

        return view('livewire.recent-searches', [
            'searches' => $searches,
            'running' => $searches->contains(fn (ListingSearch $s): bool => $s->isRunning()),
            'user' => Auth::user(),
        ]);
    }
}
