{{-- Окно JS Home Pro — без браузерных кнопок: свой «Назад», если есть куда возвращаться. --}}
<div x-data="{ canGoBack: window.history.length > 1 }" x-show="canGoBack" x-cloak class="ms-3">
    <x-filament::button
        color="gray"
        size="sm"
        icon="heroicon-m-arrow-left"
        x-on:click="window.history.back()"
    >
        {{ __('gshome.nav.back') }}
    </x-filament::button>
</div>
