<x-filament-panels::page>
    @php ($search = $this->search)

    {{-- Пока поиск идёт, перечитываем запись из своей базы (не API). --}}
    <div @if ($search->isRunning()) wire:poll.10s @endif class="grid gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium">{{ __('gshome.search.status.'.$search->status) }}</span>
            <span class="text-sm text-gray-500">{{ $search->shown() }}</span>
            @if ($search->user)
                <span class="text-sm text-gray-500">· {{ $search->user->name }}</span>
            @endif
            <a
                class="text-primary-600 text-sm underline"
                href="{{ \App\Filament\Pages\MySearches::getUrl() }}"
                >{{ __('gshome.search.back') }}</a
            >
        </div>
        @if ($search->status === 'done')
            @if ($search->zeroButFound())
                <p class="text-warning-600 dark:text-warning-400 text-sm" role="status">{{ $search->zeroButFoundNote() }}</p>
            @else
                <p class="text-sm text-gray-500">{{ $search->foundOnSitesNote() }}</p>
            @endif
        @endif
        @if ($search->message)
            <p class="text-sm">{{ $search->message }}</p>
        @endif
        @foreach ($this->siteLines() as $line)
            <p class="text-sm text-gray-500">{{ $line }}</p>
        @endforeach
        @if ($search->isRunning())
            <p class="text-sm" role="status">{{ __('gshome.search.running_note') }}</p>
        @endif
        @if ($search->isPartial())
            <p class="text-warning-600 dark:text-warning-400 text-sm" role="status">{{ __('gshome.search.partial_note') }}</p>
        @endif
    </div>

    {{ $this->table }}

    {{-- После таблицы: строки читаются при её рендере, счётчики к этому моменту уже известны. --}}
    @if ($this->incomplete)
        <p class="text-danger-600 dark:text-danger-400 text-sm" role="status">{{ __('gshome.saved.incomplete') }}</p>
    @endif
    @if ($this->hidden > 0)
        <p class="text-sm text-gray-500" role="status">{{ __('gshome.search.hidden', ['count' => $this->hidden]) }}</p>
    @endif
</x-filament-panels::page>
