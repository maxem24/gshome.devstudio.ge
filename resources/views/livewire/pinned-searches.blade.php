{{-- Один корневой элемент и при пустом списке. --}}
<div>
    @if ($searches->isNotEmpty())
        <section
            aria-labelledby="pinned-searches-title"
            class="mt-4 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        >
            <h3
                id="pinned-searches-title"
                class="px-4 pt-3 pb-1 text-sm font-semibold text-gray-950 dark:text-white"
            >
                {{ __('gshome.quick_search.pinned_title') }}
            </h3>
            <ul class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach ($searches as $s)
                    <li
                        wire:key="pinned-search-{{ $s->id }}"
                        class="flex min-h-11 items-center gap-2 px-4 py-1.5"
                    >
                        <a
                            href="{{ \App\Filament\Pages\SearchResult::getUrl(['search' => $s->id]) }}"
                            class="focus-visible:outline-primary-600 min-w-0 flex-1 truncate text-sm text-gray-950 hover:underline focus-visible:outline-2 dark:text-white"
                        >
                            {{ $s->label($user) }}
                        </a>
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-arrow-path"
                            wire:click="searchAgain({{ $s->id }})"
                            wire:loading.attr="disabled"
                        >
                            {{ __('gshome.search.mine.again') }}
                        </x-filament::button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
