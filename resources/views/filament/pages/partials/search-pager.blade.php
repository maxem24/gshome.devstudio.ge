{{-- Листание результата поиска: курсоры API, поэтому только «назад/вперёд» и диапазон «25–48». --}}
<div class="flex items-center gap-2">
    @if (count($this->rows) > 0)
        <span class="text-sm text-gray-500 dark:text-gray-400">
            {{-- Сколько всего после отбора направления, API не говорит: точное «из N» — только на последней странице. --}}
            {{
                $this->nextCursor === null
                    ? __('gshome.search.page.range', ['from' => $shownFrom, 'to' => $shownTo, 'total' => $shownTo])
                    : __('gshome.search.page.range_open', ['from' => $shownFrom, 'to' => $shownTo])
            }}
        </span>
    @endif
    <x-filament::icon-button
        icon="heroicon-m-chevron-left"
        color="gray"
        :label="__('gshome.search.page.previous')"
        :disabled="$this->page <= 1"
        wire:click="previousResultPage"
        x-on:click="window.scrollTo({ top: 0, behavior: 'smooth' })"
    />
    <span class="min-w-6 text-center text-sm font-medium">{{ $this->page }}</span>
    <x-filament::icon-button
        icon="heroicon-m-chevron-right"
        color="gray"
        :label="__('gshome.search.page.next')"
        :disabled="$this->nextCursor === null"
        wire:click="nextResultPage"
        x-on:click="window.scrollTo({ top: 0, behavior: 'smooth' })"
    />
</div>
