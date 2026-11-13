{{-- Livewire требует один корневой элемент и при пустом списке: пустой div ничего не рисует. --}}
<div @if ($running) wire:poll.10s @endif>
    @if ($searches->isNotEmpty())
        <section
            aria-labelledby="recent-searches-title"
            class="mt-4 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        >
            <h3 id="recent-searches-title" class="px-4 pt-3 pb-1 text-sm font-semibold text-gray-950 dark:text-white">
                {{ __('gshome.quick_search.recent_title') }}
            </h3>
            <ul class="divide-y divide-gray-950/5 dark:divide-white/10">
                @foreach ($searches as $s)
                    <li wire:key="recent-search-{{ $s->id }}">
                        <a
                            href="{{ \App\Filament\Pages\SearchResult::getUrl(['search' => $s->id]) }}"
                            class="flex min-h-11 items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-gray-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-600 dark:hover:bg-white/5"
                        >
                            <span class="min-w-0 flex-1 truncate text-gray-950 dark:text-white">
                                {{ \App\Search\FiltersSummary::make($s->filters, $user) }}
                            </span>
                            @if ($s->isRunning())
                                <span class="inline-flex shrink-0 items-center gap-1.5 text-gray-500 dark:text-gray-400">
                                    <x-filament::loading-indicator class="h-4 w-4" />
                                    {{ __('gshome.quick_search.recent_running') }}
                                </span>
                            @else
                                <span class="shrink-0 font-medium text-gray-600 tabular-nums dark:text-gray-300">
                                    {{ __('gshome.quick_search.recent_shown', ['count' => $s->shown()]) }}
                                </span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
