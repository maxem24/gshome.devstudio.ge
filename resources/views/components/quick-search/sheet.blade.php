{{--
    Одна панель быстрого поиска: до sm — лист снизу экрана, от sm — выпадающая под своей кнопкой.
    Состояние (panel, close, apply, clear, kb) — у родительского x-data в livewire/quick-search.
--}}
@use('Filament\Support\Icons\Heroicon')
@props(['name', 'title', 'align' => 'start'])

<template x-if="panel === '{{ $name }}'">
    <div>
        <div
            class="fixed inset-0 z-40 bg-gray-950/50 sm:bg-transparent"
            x-on:click="close()"
            aria-hidden="true"
        ></div>

        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="qs-sheet-{{ $name }}"
            x-trap="panel === '{{ $name }}'"
            x-bind:style="{ '--qs-kb': kb + 'px', '--qs-vh': vh + 'px' }"
            @class([
                'fixed inset-x-0 bottom-[var(--qs-kb,0px)] z-50 flex max-h-[min(85dvh,calc(var(--qs-vh,100dvh)-1.5rem))] flex-col',
                'rounded-t-2xl bg-white pb-[env(safe-area-inset-bottom)] shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10',
                'sm:absolute sm:inset-x-auto sm:bottom-auto sm:top-full sm:mt-2 sm:max-h-[30rem] sm:w-[min(24rem,calc(100vw-2rem))] sm:rounded-xl sm:pb-0 sm:shadow-lg',
                'sm:left-0' => $align === 'start',
                'sm:right-0' => $align === 'end',
            ])
        >
            <div class="mx-auto mt-2 h-1 w-10 shrink-0 rounded-full bg-gray-300 sm:hidden dark:bg-white/20" aria-hidden="true"></div>

            <div class="flex shrink-0 items-center justify-between gap-2 py-1 ps-4 pe-1 sm:pt-2">
                <h3 id="qs-sheet-{{ $name }}" class="text-base font-semibold text-gray-950 dark:text-white">{{ $title }}</h3>
                <button
                    type="button"
                    x-on:click="close()"
                    class="flex size-11 items-center justify-center rounded-lg text-gray-400 hover:text-gray-600 focus-visible:outline-2 focus-visible:outline-primary-600 dark:hover:text-gray-200"
                >
                    <span class="sr-only">{{ __('gshome.quick_search.close') }}</span>
                    <x-filament::icon :icon="Heroicon::XMark" class="size-5" />
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-4">
                {{ $slot }}
            </div>

            <div class="flex shrink-0 gap-3 border-t border-gray-950/5 p-3 dark:border-white/10">
                <button
                    type="button"
                    x-on:click="clear()"
                    class="h-11 flex-1 rounded-lg px-4 text-sm font-medium text-gray-700 ring-1 ring-gray-950/10 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-600 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/5"
                >{{ __('gshome.quick_search.reset') }}</button>
                <button
                    type="button"
                    x-on:click="apply()"
                    class="h-11 flex-1 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:bg-primary-500 dark:hover:bg-primary-400"
                >{{ __('gshome.quick_search.done') }}</button>
            </div>
        </div>
    </div>
</template>
