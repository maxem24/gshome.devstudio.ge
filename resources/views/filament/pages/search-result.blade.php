<x-filament-panels::page>
    @php
        $search = $this->search;
        $this->loadRows();
        $total = $this->total();
        $shownFrom = ($this->page - 1) * \App\Filament\Pages\SearchResult::PER_PAGE + 1;
        $shownTo = $shownFrom + count($this->rows) - 1;
    @endphp

    {{-- Пока поиск идёт, перечитываем запись из своей базы (не API). --}}
    <div @if ($search->isRunning()) wire:poll.10s @endif>
        <x-filament::section compact>
            <div class="grid gap-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="grid gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge
                                :color="$search->statusColor()"
                                >{{ __('gshome.search.status.'.$search->status) }}</x-filament::badge
                            >
                            <span
                                class="text-sm font-medium text-gray-950 dark:text-white"
                                >{{ \App\Search\FiltersSummary::make($search->filters ?? [], $search->user) }}</span
                            >
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $search->started_at?->timezone((string) config('crm.timezone'))->format('d.m.Y H:i') }}
                            @if ($search->user)
                                · {{ $search->user->name }}
                            @endif
                        </p>
                    </div>
                    <div class="flex items-baseline gap-6">
                        <div class="text-end">
                            <div class="text-2xl font-bold text-gray-950 dark:text-white">
                                {{ $search->shown() }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('gshome.search.page.in_result') }}
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="text-2xl font-bold text-gray-400 dark:text-gray-500">
                                {{ $search->found }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('gshome.search.page.on_sites') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Filament\Pages\MySearches::sites($search) as $site)
                        <div
                            class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm dark:border-white/10"
                            @if ($site['message']) title="{{ $site['message'] }}" @endif
                        >
                            <span
                                class="font-medium text-gray-950 dark:text-white"
                                >{{ $site['name'] }}</span
                            >
                            <x-filament::badge
                                :color="$site['color']"
                                size="sm"
                                >{{ $site['label'] }}</x-filament::badge
                            >
                            <span
                                class="text-gray-500 dark:text-gray-400"
                                >{{ $site['found'] }}</span
                            >
                        </div>
                    @endforeach
                </div>

                @if ($search->message)
                    <p
                        @class ([
                        'text-sm',
                        'text-warning-600 dark:text-warning-400' => $search->isPartial() || $search->siteNotes() !== [],
                        'text-gray-700 dark:text-gray-300' => ! $search->isPartial() && $search->siteNotes() === [],
                    ])
                    >{{ $search->message }}</p>
                @endif
                {{-- Пояснения сайтов — только когда общего сообщения нет: иначе одно и то же три раза. --}}
                @foreach ($search->message ? [] : \App\Filament\Pages\MySearches::sites($search) as $site)
                    @if ($site['message'] && $site['color'] !== 'success')
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $site['name'] }}: {{ $site['message'] }}</p>
                    @endif
                @endforeach
                @if ($search->truncated)
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $search->foundOnSitesNote() }}</p>
                @endif
                @if ($search->status === 'done' && $search->zeroButFound())
                    <p class="text-warning-600 dark:text-warning-400 text-sm" role="status">{{ $search->zeroButFoundNote() }}</p>
                @endif
                @if ($search->isRunning())
                    <p class="text-sm" role="status">{{ __('gshome.search.running_note') }}</p>
                @endif
                @if ($search->isPartial() && ! $search->message)
                    <p class="text-warning-600 dark:text-warning-400 text-sm" role="status">{{ __('gshome.search.partial_note') }}</p>
                @endif
            </div>
        </x-filament::section>
    </div>

    @if ($total > 0)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <label
                    for="search-sort"
                    class="text-sm text-gray-500 dark:text-gray-400"
                    >{{ __('gshome.listings.sort') }}</label
                >
                <x-filament::input.wrapper>
                    <x-filament::input.select id="search-sort" wire:model.live="sort">
                        @foreach ($this->sortOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            @include ('filament.pages.partials.search-pager')
        </div>
        @if ($sort !== 'new')
            <p class="-mt-4 text-xs text-gray-500 dark:text-gray-400">{{ __('gshome.listings.sort_hint') }}</p>
        @endif
    @endif

    {{ $this->table }}

    @if ($total > 0 && ($this->page > 1 || $this->nextCursor !== null))
        <div class="flex justify-end">
            @include ('filament.pages.partials.search-pager')
        </div>
    @endif

    @if ($this->incomplete)
        <p class="text-danger-600 dark:text-danger-400 text-sm" role="status">{{ __('gshome.saved.incomplete') }}</p>
    @endif
    @if ($this->hidden > 0)
        <p class="text-sm text-gray-500" role="status">{{ __('gshome.search.hidden', ['count' => $this->hidden]) }}</p>
    @endif
</x-filament-panels::page>
