<x-filament-panels::page>
    {{ $this->table }}

    {{-- После таблицы: строки читаются при её рендере, флаг к этому моменту уже известен. --}}
    @if ($this->incomplete)
        <p class="text-danger-600 dark:text-danger-400 text-sm" role="status">{{ __('gshome.saved.incomplete') }}</p>
    @endif
</x-filament-panels::page>
