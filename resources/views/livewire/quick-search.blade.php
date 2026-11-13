@use('Filament\Support\Icons\Heroicon')
@php
    $chipBase = 'inline-flex h-11 max-w-full items-center gap-1.5 rounded-full px-4 text-sm font-medium ring-1 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600';
    $chipEmpty = 'bg-white text-gray-700 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-gray-200 dark:ring-white/20 dark:hover:bg-white/10';
    $chipFilled = 'bg-primary-50 font-semibold text-primary-700 ring-2 ring-primary-600 hover:bg-primary-100 dark:bg-primary-400/10 dark:text-primary-200 dark:ring-primary-400 dark:hover:bg-primary-400/20';
    $segment = 'relative flex min-h-11 cursor-pointer items-center justify-center rounded-md px-3 text-center text-sm font-medium text-gray-600 transition-colors has-[:checked]:bg-white has-[:checked]:text-gray-950 has-[:checked]:shadow-sm has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-primary-600 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-40 dark:text-gray-400 dark:has-[:checked]:bg-gray-700 dark:has-[:checked]:text-white';
    $field = 'h-11 w-full rounded-lg border-0 bg-white text-base text-gray-950 ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 sm:text-sm dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500';
    $option = 'flex min-h-11 w-full items-center justify-between gap-3 rounded-lg px-3 text-start text-sm text-gray-700 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-600 dark:text-gray-200 dark:hover:bg-white/5';
    $preset = 'h-11 rounded-full px-4 text-sm font-medium ring-1 ring-gray-950/10 dark:ring-white/20';
    $presetOn = '!bg-primary-600 !text-white !ring-primary-600 dark:!bg-primary-500';
@endphp

<div
    data-place="{{ json_encode($place) }}"
    x-data="{
        panel: null,
        trigger: null,
        presetOn: @js($presetOn),
        expanded: @js(! $compact),
        kb: 0,
        vh: window.innerHeight,
        price: { min: null, max: null },
        area: { min: null, max: null },
        beds: [],
        place: { city: null, districts: [], subdistricts: [] },
        level: 'cities',
        term: '',
        results: [],
        loading: false,
        requests: 0,
        num(v) { return v === '' || v === null || v === undefined || isNaN(+v) ? null : Math.max(0, Math.round(+v)) },
        show(name) {
            if (name === 'price') this.price = { min: $wire.price_usd_min, max: $wire.price_usd_max }
            if (name === 'area') this.area = { min: $wire.area_min, max: $wire.area_max }
            if (name === 'beds') this.beds = [...$wire.bedrooms]
            if (name === 'place') {
                this.place = JSON.parse(this.$root.dataset.place)
                this.go(this.place.city ? 'districts' : 'cities')
            }
            this.trigger = document.activeElement
            this.panel = name
        },
        close() {
            this.panel = null
            // Лист убран из DOM вместе с x-trap: фокус возвращаем на кнопку, которая его открыла.
            this.$nextTick(() => this.trigger?.focus({ preventScroll: true }))
        },
        clear() {
            if (this.panel === 'price') this.price = { min: null, max: null }
            if (this.panel === 'area') this.area = { min: null, max: null }
            if (this.panel === 'beds') this.beds = []
            if (this.panel === 'place') { this.place.districts = []; this.place.subdistricts = []; this.go(this.place.city ? 'districts' : 'cities') }
        },
        apply() {
            const p = this.panel
            this.close()
            if (p === 'place') {
                $wire.applyPlace(this.place.city?.[0] ?? null, this.place.districts.map(d => d[0]), this.place.subdistricts.map(s => s[0]))
                return
            }
            if (p === 'price') { $wire.price_usd_min = this.num(this.price.min); $wire.price_usd_max = this.num(this.price.max) }
            if (p === 'area') { $wire.area_min = this.num(this.area.min); $wire.area_max = this.num(this.area.max) }
            if (p === 'beds') $wire.bedrooms = [...this.beds].sort()
            $wire.$commit()
        },
        toggleBed(n) { this.beds = this.beds.includes(n) ? this.beds.filter(b => b !== n) : [...this.beds, n] },
        go(level) { this.level = level; this.term = ''; this.load() },
        async load() {
            const parents = this.level === 'districts' ? [this.place.city?.[0]] : this.place.districts.map(d => d[0])
            // Ответы могут прийти не по порядку: показываем только ответ на последний запрос.
            const ticket = ++this.requests
            this.loading = true
            try {
                const rows = await $wire.placeOptions(this.level, this.term, parents)
                if (ticket === this.requests) this.results = rows
            } finally {
                if (ticket === this.requests) this.loading = false
            }
        },
        chosen(row) {
            if (this.level === 'cities') return this.place.city?.[0] === row[0]
            return this.place[this.level].some(r => r[0] === row[0])
        },
        pick(row) {
            if (this.level === 'cities') {
                if (this.place.city?.[0] !== row[0]) { this.place = { city: row, districts: [], subdistricts: [] } }
                this.go('districts')
                return
            }
            const list = this.place[this.level]
            this.place[this.level] = this.chosen(row) ? list.filter(r => r[0] !== row[0]) : [...list, row]
            if (this.level === 'districts') {
                const kept = this.place.districts.map(d => d[0])
                this.place.subdistricts = this.place.subdistricts.filter(s => kept.includes(s[2]))
            }
        },
        viewport() {
            const v = window.visualViewport
            if (! v) return
            this.vh = v.height
            this.kb = Math.max(0, Math.round(window.innerHeight - v.height - v.offsetTop))
        },
    }"
    x-init="window.visualViewport?.addEventListener('resize', () => viewport()); window.visualViewport?.addEventListener('scroll', () => viewport())"
    x-effect="document.documentElement.classList.toggle('overflow-hidden', panel !== null && ! window.matchMedia('(min-width: 640px)').matches)"
    x-on:keydown.escape.window="panel && close()"
    class="quick-search"
>
    @if ($compact)
        <button
            type="button"
            data-quick-search-summary
            x-show="! expanded"
            x-on:click="expanded = true"
            x-bind:aria-expanded="expanded"
            class="flex h-11 w-full items-center gap-2 rounded-full bg-white px-4 text-start text-sm text-gray-700 shadow-sm ring-1 ring-gray-950/10 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-600 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/5"
        >
            <x-filament::icon :icon="Heroicon::MagnifyingGlass" class="size-5 shrink-0 text-gray-400" />
            <span class="min-w-0 flex-1 truncate">{{ $summary !== '' ? $summary : __('gshome.quick_search.edit') }}</span>
            <x-filament::icon :icon="Heroicon::ChevronDown" class="size-4 shrink-0 text-gray-400" />
            <span class="sr-only">{{ __('gshome.quick_search.edit') }}</span>
        </button>
    @endif

    <form
        wire:submit="submit"
        role="search"
        @if ($compact) x-show="expanded" x-cloak @endif
        class="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:p-5 dark:bg-gray-900 dark:ring-white/10"
    >
        <div class="flex flex-col gap-2 md:flex-row md:items-start">
            <div>
                <div role="radiogroup" aria-label="{{ __('gshome.quick_search.mode') }}" class="grid grid-cols-2 gap-1 rounded-lg bg-gray-100 p-1 md:w-80 dark:bg-white/5">
                    <label class="{{ $segment }}"><input class="sr-only" type="radio" wire:model.live="mode" name="mode" value="fresh">{{ __('gshome.quick_search.mode_fresh') }}</label>
                    <label class="{{ $segment }}"><input class="sr-only" type="radio" wire:model.live="mode" name="mode" value="base" @disabled(! $baseAllowed)>{{ __('gshome.quick_search.mode_base') }}</label>
                </div>
                @if (! $baseAllowed)
                    <p class="mt-1.5 px-1 text-xs text-gray-500 dark:text-gray-400">{{ __('gshome.search.base_own_only') }}</p>
                @endif
            </div>

            @if ($dealOptions !== [])
                <div role="radiogroup" aria-label="{{ __('gshome.quick_search.deal') }}" class="grid grid-cols-2 gap-1 rounded-lg bg-gray-100 p-1 md:w-56 dark:bg-white/5">
                    @foreach ($dealOptions as $value => $label)
                        <label class="{{ $segment }}"><input class="sr-only" type="radio" wire:model.live="deal" name="deal" value="{{ $value }}">{{ $label }}</label>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-start">
            <label class="relative block md:w-44 md:shrink-0">
                <span class="sr-only">{{ __('gshome.quick_search.property_type') }}</span>
                <x-filament::icon :icon="Heroicon::OutlinedHome" class="pointer-events-none absolute start-3 top-3 size-5 text-gray-400" />
                <select wire:model.live="property_type_id" name="property_type_id" class="{{ $field }} ps-10">
                    <option value="">{{ __('gshome.listings.all') }}</option>
                    @foreach ($types as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <div class="relative md:min-w-48 md:flex-1">
                <button
                    type="button"
                    x-on:click="show('place')"
                    x-bind:aria-expanded="panel === 'place'"
                    aria-haspopup="dialog"
                    class="{{ $field }} flex items-center gap-2 px-3 text-start"
                >
                    <x-filament::icon :icon="Heroicon::OutlinedMapPin" class="size-5 shrink-0 text-gray-400" />
                    <span @class(['min-w-0 flex-1 truncate', 'text-gray-500 dark:text-gray-400' => $placeLabel === null])>{{ $placeLabel ?? __('gshome.quick_search.place_any') }}</span>
                    <x-filament::icon :icon="Heroicon::ChevronDown" class="size-4 shrink-0 text-gray-400" />
                </button>

                <x-quick-search.sheet name="place" :title="__('gshome.quick_search.place')">
                    <div class="sticky top-0 z-10 -mx-4 space-y-2 bg-white px-4 pb-2 dark:bg-gray-900">
                        <div class="grid grid-cols-3 gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5">
                            @foreach (['cities' => 'city', 'districts' => 'district', 'subdistricts' => 'subdistrict'] as $level => $key)
                                <button
                                    type="button"
                                    x-on:click="go('{{ $level }}')"
                                    x-bind:disabled="{{ $level === 'districts' ? '! place.city' : ($level === 'subdistricts' ? '! place.districts.length' : 'false') }}"
                                    x-bind:class="level === '{{ $level }}' ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-600 dark:text-gray-400'"
                                    class="min-h-11 truncate rounded-md px-2 text-sm font-medium disabled:opacity-40"
                                >
                                    {{ __('gshome.quick_search.'.$key) }}
                                    @if ($level !== 'cities')
                                        <span x-show="place.{{ $level }}.length" x-text="place.{{ $level }}.length" class="ms-0.5 text-primary-600 dark:text-primary-400"></span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                        <label class="relative block">
                            <span class="sr-only">{{ __('gshome.quick_search.place_search') }}</span>
                            <x-filament::icon :icon="Heroicon::MagnifyingGlass" class="pointer-events-none absolute start-3 top-3 size-5 text-gray-400" />
                            <input
                                type="search"
                                x-model="term"
                                x-on:input.debounce.250ms="load()"
                                x-on:keydown.enter.prevent="load()"
                                enterkeyhint="search"
                                autocomplete="off"
                                placeholder="{{ __('gshome.quick_search.place_search') }}"
                                class="{{ $field }} ps-10"
                            >
                        </label>
                        <div x-show="level !== 'cities' && place[level].length" class="flex flex-wrap gap-1.5">
                            <template x-for="row in (level === 'cities' ? [] : place[level])" x-bind:key="row[0]">
                                <button type="button" x-on:click="pick(row)" class="inline-flex min-h-11 items-center gap-1.5 rounded-full bg-primary-50 ps-3.5 pe-3 text-sm font-medium text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">
                                    <span x-text="row[1]"></span>
                                    <x-filament::icon :icon="Heroicon::XMark" class="size-3.5" />
                                </button>
                            </template>
                        </div>
                    </div>

                    <ul class="space-y-0.5" x-bind:aria-busy="loading">
                        <template x-for="row in results" x-bind:key="row[0]">
                            <li>
                                <button type="button" x-on:click="pick(row)" x-bind:aria-pressed="chosen(row)" class="{{ $option }}">
                                    <span x-text="row[1]" class="truncate"></span>
                                    <x-filament::icon x-show="chosen(row)" :icon="Heroicon::Check" class="size-5 shrink-0 text-primary-600 dark:text-primary-400" />
                                </button>
                            </li>
                        </template>
                    </ul>
                    <p x-show="! loading && ! results.length" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('gshome.quick_search.nothing_found') }}</p>
                </x-quick-search.sheet>
            </div>

            <div class="flex flex-wrap gap-2 md:shrink-0">
                <div class="relative">
                    <button type="button" x-on:click="show('price')"
                    x-bind:aria-expanded="panel === 'price'" aria-haspopup="dialog" class="{{ $chipBase }} {{ $chips['price'] ? $chipFilled : $chipEmpty }}">
                        <span class="truncate">{{ $chips['price'] ?? __('gshome.quick_search.price') }}</span>
                        <x-filament::icon :icon="Heroicon::ChevronDown" class="size-4 shrink-0 opacity-60" />
                    </button>
                    <x-quick-search.sheet name="price" :title="__('gshome.quick_search.price_title')">
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block text-sm text-gray-600 dark:text-gray-300">{{ __('gshome.quick_search.min') }}
                                <input type="number" inputmode="numeric" min="0" step="1" x-model="price.min" x-on:keydown.enter.prevent="apply()" class="{{ $field }} mt-1 px-3">
                            </label>
                            <label class="block text-sm text-gray-600 dark:text-gray-300">{{ __('gshome.quick_search.max') }}
                                <input type="number" inputmode="numeric" min="0" step="1" x-model="price.max" x-on:keydown.enter.prevent="apply()" class="{{ $field }} mt-1 px-3">
                            </label>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($pricePresets as [$value, $label])
                                <button
                                    type="button"
                                    data-preset="{{ $value }}"
                                    x-on:click="price = { min: null, max: {{ $value }} }"
                                    x-bind:class="num(price.max) === {{ $value }} && num(price.min) === null ? presetOn : ''"
                                    class="{{ $preset }} {{ $chipEmpty }}"
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    </x-quick-search.sheet>
                </div>

                <div class="relative">
                    <button type="button" x-on:click="show('area')"
                    x-bind:aria-expanded="panel === 'area'" aria-haspopup="dialog" class="{{ $chipBase }} {{ $chips['area'] ? $chipFilled : $chipEmpty }}">
                        <span class="truncate">{{ $chips['area'] ?? __('gshome.quick_search.area') }}</span>
                        <x-filament::icon :icon="Heroicon::ChevronDown" class="size-4 shrink-0 opacity-60" />
                    </button>
                    <x-quick-search.sheet name="area" :title="__('gshome.quick_search.area_title')">
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block text-sm text-gray-600 dark:text-gray-300">{{ __('gshome.quick_search.min') }}
                                <input type="number" inputmode="numeric" min="0" step="1" x-model="area.min" x-on:keydown.enter.prevent="apply()" class="{{ $field }} mt-1 px-3">
                            </label>
                            <label class="block text-sm text-gray-600 dark:text-gray-300">{{ __('gshome.quick_search.max') }}
                                <input type="number" inputmode="numeric" min="0" step="1" x-model="area.max" x-on:keydown.enter.prevent="apply()" class="{{ $field }} mt-1 px-3">
                            </label>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($areaPresets as [$min, $max, $label])
                                <button
                                    type="button"
                                    data-area="{{ $min }}-{{ $max }}"
                                    x-on:click="area = { min: {{ $min ?? 'null' }}, max: {{ $max ?? 'null' }} }"
                                    x-bind:class="num(area.min) === {{ $min ?? 'null' }} && num(area.max) === {{ $max ?? 'null' }} ? presetOn : ''"
                                    class="{{ $preset }} {{ $chipEmpty }}"
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    </x-quick-search.sheet>
                </div>

                <div class="relative">
                    <button type="button" x-on:click="show('beds')"
                    x-bind:aria-expanded="panel === 'beds'" aria-haspopup="dialog" class="{{ $chipBase }} {{ $chips['bedrooms'] ? $chipFilled : $chipEmpty }}">
                        <span class="truncate">{{ $chips['bedrooms'] ?? __('gshome.quick_search.bedrooms') }}</span>
                        <x-filament::icon :icon="Heroicon::ChevronDown" class="size-4 shrink-0 opacity-60" />
                    </button>
                    <x-quick-search.sheet name="beds" :title="__('gshome.quick_search.bedrooms')" align="end">
                        <div class="grid grid-cols-5 gap-2" role="group" aria-label="{{ __('gshome.quick_search.bedrooms') }}">
                            @foreach (['1', '2', '3', '4', '5'] as $n)
                                <button
                                    type="button"
                                    x-on:click="toggleBed('{{ $n }}')"
                                    x-bind:aria-pressed="beds.includes('{{ $n }}')"
                                    x-bind:class="beds.includes('{{ $n }}') ? presetOn : ''"
                                    class="h-12 rounded-full text-base font-semibold ring-1 {{ $chipEmpty }}"
                                >{{ $n === '5' ? '5+' : $n }}</button>
                            @endforeach
                        </div>
                    </x-quick-search.sheet>
                </div>
            </div>

            <div class="md:ms-auto md:shrink-0">
                <button type="submit" @disabled(! $this->canSubmit()) wire:loading.attr="disabled" wire:target="submit" class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary-600 px-6 text-base font-semibold text-white shadow-sm transition-colors hover:bg-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 disabled:cursor-not-allowed disabled:opacity-50 md:h-11 md:rounded-lg dark:bg-primary-500 dark:hover:bg-primary-400">
                    <x-filament::icon wire:loading.remove wire:target="submit" :icon="Heroicon::MagnifyingGlass" class="size-5" />
                    <x-filament::loading-indicator wire:loading wire:target="submit" class="size-5" />
                    {{ $mode === 'base' ? __('gshome.quick_search.submit_base') : __('gshome.quick_search.submit_fresh') }}
                </button>
                <p class="mt-1.5 text-center text-xs text-gray-500 md:text-end dark:text-gray-400">
                    {{ $mode === 'base' ? __('gshome.quick_search.caption_base') : __('gshome.quick_search.caption_fresh') }}
                </p>
            </div>
        </div>

        @if ($mode === 'fresh' && $this->ssWillSkip())
            <p class="flex gap-2 text-sm text-gray-500 dark:text-gray-400">
                <x-filament::icon :icon="Heroicon::OutlinedInformationCircle" class="size-5 shrink-0" />
                <span>{{ __('gshome.quick_search.ss_skip') }}</span>
            </p>
        @endif

        @if ($collectorsOffline)
            <div role="alert" class="flex flex-col gap-3 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 ring-1 ring-warning-600/20 sm:flex-row sm:items-center dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30">
                <p class="flex flex-1 gap-2">
                    <x-filament::icon :icon="Heroicon::ExclamationTriangle" class="size-5 shrink-0 text-warning-600 dark:text-warning-400" />
                    <span>{{ __('gshome.quick_search.collectors_offline') }}</span>
                </p>
                @if ($baseAllowed)
                    <button type="button" wire:click="useBase" class="h-11 shrink-0 rounded-lg bg-white px-4 font-semibold text-warning-800 ring-1 ring-warning-600/30 hover:bg-warning-100 dark:bg-transparent dark:text-warning-300 dark:ring-warning-400/40 dark:hover:bg-warning-400/10">{{ __('gshome.quick_search.use_base') }}</button>
                @endif
            </div>
        @endif
    </form>
</div>
