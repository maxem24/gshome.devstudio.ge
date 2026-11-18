<?php

namespace App\Livewire;

use App\Filament\Pages\Concerns\RepeatsSearches;
use App\Models\ListingSearch;
use App\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** Закреплённые поиски человека на инфопанели: открыть результат или повторить одной кнопкой. */
final class PinnedSearches extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use RepeatsSearches;

    protected function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return Collection<int, ListingSearch> */
    public function searches(): Collection
    {
        return ListingSearch::query()->with('user')
            ->where('user_id', $this->viewer()->id)
            ->whereNotNull('pinned_at')
            ->orderByDesc('pinned_at')
            ->limit(10)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.pinned-searches', ['searches' => $this->searches(), 'user' => $this->viewer()]);
    }
}
