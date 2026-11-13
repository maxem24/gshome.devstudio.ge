<x-filament-panels::page>
    <h2 class="text-xl font-semibold">{{ __('gshome.quick_search.title') }}</h2>
    @livewire (\App\Livewire\QuickSearch::class)
    @livewire (\App\Livewire\RecentSearches::class)
</x-filament-panels::page>
