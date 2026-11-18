<x-filament-panels::page>
    @livewire (\App\Livewire\DayGreeting::class)
    <h2 class="text-xl font-semibold">{{ __('gshome.quick_search.title') }}</h2>
    @livewire (\App\Livewire\QuickSearch::class)
    @livewire (\App\Livewire\PinnedSearches::class)
    @livewire (\App\Livewire\RecentSearches::class)
</x-filament-panels::page>
