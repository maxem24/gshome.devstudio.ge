<x-filament-panels::page>
    @if ($error)
        <x-filament::section><p>{{ $error }}</p></x-filament::section>
    @else
        @php ($banner = $this->banner())
        @if ($banner)
            <x-filament::section>
                <p class="font-medium">
                    @if ($banner['mine'])
                        {{ __('gshome.crm.pins.in_work_mine') }}
                    @elseif ($banner['name'])
                        {{ __('gshome.crm.pins.in_work_by', ['name' => $banner['name']]) }}
                    @else
                        {{ __('gshome.crm.pins.in_work_other') }}
                    @endif
                </p>
                @if ($banner['preferences'])
                    <div class="mt-3 text-sm">
                        <p class="font-medium text-gray-950 dark:text-white">{{ __('gshome.crm.pins.preferences_title') }}</p>
                        <ul class="mt-1 space-y-0.5 text-gray-700 dark:text-gray-200">
                            @foreach ($banner['preferences'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-filament::section>
        @endif
        @php ($matches = $this->matches())
        @if ($matches !== null)
            @include ('filament.crm.matches', ['title' => __('gshome.crm.matching.clients_title'), 'rows' => $matches])
        @endif
        @if ($refresh !== [])
            {{-- Актуализация: пока идёт — опрос раз в poll_ms; по концу опрос исчезает из разметки --}}
            <div
                @if ($refreshId !== null) wire:poll.{{ (int) ($refresh['poll_ms'] ?? 2000) }}ms="pollRefresh" @endif
            >
                <x-filament::section>
                    <div class="space-y-2" role="status">
                        @if ($refreshId !== null)
                            <p class="font-medium">
                                {{ __('gshome.listings.refresh.running') }}, {{ __('gshome.listings.refresh.eta', ['minutes' => $refresh['eta_minutes']]) }}
                            </p>
                        @elseif ($refresh['removed'] ?? false)
                            <p class="text-danger-600 dark:text-danger-400 text-lg font-semibold">
                                {{ $refresh['removed_at'] ? __('gshome.listings.refresh.removed', ['date' => $refresh['removed_at']]) : __('gshome.listings.refresh.removed_unknown') }}
                            </p>
                        @elseif ($refresh['lost'] ?? false)
                            <p class="text-warning-600 dark:text-warning-400 font-medium">{{ __('gshome.listings.refresh.lost') }}</p>
                        @elseif ($refresh['timeout'] ?? null)
                            <p class="text-warning-600 dark:text-warning-400 font-medium">{{ $refresh['timeout'] }}</p>
                        @elseif ($refresh['stale'] ?? null)
                            <p class="text-warning-600 dark:text-warning-400 font-medium">{{ $refresh['stale'] }}</p>
                        @elseif ($refresh['finished'] ?? false)
                            <p class="font-medium">{{ __('gshome.listings.refresh.done') }}</p>
                        @endif
                        @if ($refresh['price_before'] ?? null)
                            <p>{{ __('gshome.listings.refresh.price_changed', ['price' => $refresh['price_before']]) }}</p>
                        @endif
                        @if ($refresh['error'] ?? null)
                            <p class="text-warning-600 dark:text-warning-400 text-sm">{{ $refresh['error'] }}</p>
                        @endif
                        @if ($refreshId === null && ($refresh['summary'] ?? null))
                            <p class="text-sm">{{ $refresh['summary'] }}</p>
                        @elseif ($refresh['items'] ?? [])
                            <ul class="text-sm">
                                @foreach ($refresh['items'] as $item)
                                    <li>
                                        @if ($item['url'])
                                            <a
                                                href="{{ $item['url'] }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="underline"
                                                >{{ $item['site'] }}</a
                                            >
                                        @else
                                            {{ $item['site'] }}
                                        @endif
                                        — {{ $item['text'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($refresh['truncated'] ?? false)
                            <p class="text-sm text-gray-500">{{ __('gshome.listings.refresh.truncated') }}</p>
                        @endif
                    </div>
                </x-filament::section>
            </div>
        @endif
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge
                    :color="$card['own']['color']"
                    >{{ $card['own']['label'] }}</x-filament::badge
                >
                @if ($card['same_owner'])
                    <span>{{ __('gshome.ownership.same_owner') }}</span>
                @endif
                @if ($card['company_line'])
                    <span>{{ $card['company_line'] }}</span>
                @endif
                @if ($card['own']['name'])
                    <span>{{ $card['own']['name'] }}</span>
                @endif
                @if ($card['agency_in_group'])
                    <span>{{ $card['agency_in_group'] }}</span>
                @endif
                @if ($card['owner_still_listed'])
                    <span>{{ $card['owner_still_listed'] }}</span>
                @endif
                @if ($card['group'])
                    <x-filament::badge
                        :color="$card['group']['color']"
                        >{{ __('gshome.listings.group_member', ['name' => $card['group']['name'] ?? $card['group']['company']]) }}</x-filament::badge
                    >
                @endif
            </div>
        </x-filament::section>

        {{-- Телефон: цена, фото, факты. Компьютер: фото слева, цена и факты справа. --}}
        <div class="grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(18rem,24rem)] lg:items-start">
            <div
                class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 lg:col-start-2 lg:row-start-1 dark:bg-gray-900 dark:ring-white/10"
            >
                <p class="text-3xl font-bold tracking-tight text-gray-950 dark:text-white">
                    {{ $card['price'] }}
                </p>
                @if ($card['price_gel'])
                    <p class="text-gray-500 dark:text-gray-400">{{ $card['price_gel'] }}</p>
                @endif
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex h-8 items-center rounded-full bg-gray-100 px-3 text-sm font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200"
                        >{{ $card['direction'] }}</span
                    >
                    @if ($card['negotiable'])
                        <span
                            class="text-sm text-gray-500 dark:text-gray-400"
                            >{{ __('gshome.listings.bargain') }}</span
                        >
                    @endif
                </div>
                @if ($card['call_href'])
                    <a
                        href="{{ $card['call_href'] }}"
                        class="bg-primary-600 hover:bg-primary-500 focus-visible:outline-primary-600 dark:bg-primary-500 dark:hover:bg-primary-400 mt-4 flex h-12 w-full items-center justify-center gap-2 rounded-xl text-base font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        {{ __('gshome.listings.call') }}
                        @if ($card['contact_phone'])
                            <span class="font-medium">{{ $card['contact_phone'] }}</span>
                        @endif
                    </a>
                @endif
                <p class="mt-3 text-sm text-gray-700 dark:text-gray-200">
                    {{ $card['seller'] }}
                    @if ($card['contact_name'])
                        · {{ $card['contact_name'] }}
                    @endif
                    @if ($card['viber']) · Viber @endif
                    @if ($card['whatsapp']) · WhatsApp @endif
                </p>
            </div>

            @if ($card['images'])
                <div
                    data-listing-carousel
                    x-data="{
                        i: 0,
                        n: {{ count($card['images']) }},
                        startX: null,
                        prev() { this.i = (this.i - 1 + this.n) % this.n },
                        next() { this.i = (this.i + 1) % this.n },
                    }"
                    role="region"
                    aria-roledescription="carousel"
                    aria-label="{{ __('gshome.listings.photos') }}"
                    tabindex="0"
                    x-on:keydown.arrow-left.prevent="prev()"
                    x-on:keydown.arrow-right.prevent="next()"
                    x-on:touchstart.passive="startX = $event.changedTouches[0].clientX"
                    x-on:touchend.passive="
                        if (startX === null) return;
                        const dx = $event.changedTouches[0].clientX - startX;
                        if (dx > 40) prev();
                        if (dx < -40) next();
                        startX = null;
                    "
                    class="lg:col-start-1 lg:row-span-2 lg:row-start-1"
                >
                    <div
                        class="relative overflow-hidden rounded-xl bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
                    >
                        @foreach ($card['images'] as $index => $image)
                            <a
                                href="{{ $image['url'] }}"
                                target="_blank"
                                rel="noopener"
                                x-show="i === {{ $index }}"
                                @if ($index !== 0) x-cloak @endif
                            >
                                <img
                                    src="{{ $image['url'] }}"
                                    alt=""
                                    class="aspect-[4/3] w-full object-cover lg:aspect-auto lg:h-[min(32rem,calc(100vh-10rem))]"
                                    @if ($index === 0) loading="eager" @else loading="lazy" @endif
                                />
                            </a>
                        @endforeach
                        @if (count($card['images']) > 1)
                            <button
                                type="button"
                                x-on:click="prev()"
                                class="focus-visible:outline-primary-600 absolute start-2 top-1/2 flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-gray-950 shadow-sm focus-visible:outline-2 dark:bg-gray-950/80 dark:text-white"
                            >
                                <span class="sr-only">{{ __('gshome.listings.photos') }}</span>
                                <x-filament::icon icon="heroicon-o-chevron-left" class="size-5" />
                            </button>
                            <button
                                type="button"
                                x-on:click="next()"
                                class="focus-visible:outline-primary-600 absolute end-2 top-1/2 flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-gray-950 shadow-sm focus-visible:outline-2 dark:bg-gray-950/80 dark:text-white"
                            >
                                <span class="sr-only">{{ __('gshome.listings.photos') }}</span>
                                <x-filament::icon icon="heroicon-o-chevron-right" class="size-5" />
                            </button>
                            <p
                                class="absolute end-2 bottom-2 rounded-full bg-gray-950/70 px-3 py-1 text-sm font-medium text-white"
                                x-text="@js(__('gshome.listings.photo_position')).replace(':current', i + 1).replace(':total', n)"
                            >
                                {{ __('gshome.listings.photo_position', ['current' => 1, 'total' => count($card['images'])]) }}
                            </p>
                        @endif
                    </div>
                    @if (count($card['images']) > 1)
                        <div class="mt-2 hidden gap-2 overflow-x-auto lg:flex">
                            @foreach ($card['images'] as $index => $image)
                                <button
                                    type="button"
                                    x-on:click="i = {{ $index }}"
                                    x-bind:class="i === {{ $index }} ? 'ring-primary-600 dark:ring-primary-400' : 'ring-transparent'"
                                    class="h-16 w-20 shrink-0 overflow-hidden rounded-lg ring-2"
                                >
                                    <img
                                        src="{{ $image['url_thumb'] ?? $image['url'] }}"
                                        alt=""
                                        class="h-full w-full object-cover"
                                    />
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="lg:col-start-2">
                <x-filament::section :heading="__('gshome.listings.characteristics')">
                    <dl class="space-y-2 text-sm">
                        @foreach ([
                        __('gshome.listings.rooms') => $card['rooms'],
                        __('gshome.listings.area') => $card['area'],
                        __('gshome.listings.floor') => $card['floor'],
                        __('gshome.listings.property_type') => $card['property_type'],
                    ] as $label => $value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-500">{{ $label }}</dt>
                                <dd class="text-right">{{ filled($value) ? $value : '—' }}</dd>
                            </div>
                        @endforeach
                        <div>
                            <dt class="text-gray-500">{{ __('gshome.listings.address') }}</dt>
                            <dd>
                                {{ collect([$card['district'], $card['address']])->filter()->implode(', ') ?: '—' }}
                                @if ($card['map'])
                                    <a
                                        href="{{ $card['map'] }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-primary-600"
                                        >{{ __('gshome.listings.on_map') }}</a
                                    >
                                @endif
                            </dd>
                        </div>
                        <div>
                            {{ __('gshome.listings.appeared', ['date' => $card['created_at'] ?? '—', 'source' => $card['source']]) }}
                        </div>
                    </dl>
                </x-filament::section>
            </div>

            <div class="space-y-4 lg:col-span-2">
                @if ($card['description'])
                    <x-filament::section :heading="__('gshome.listings.description')">
                        <p class="whitespace-pre-line">{{ $card['description'] }}</p>
                    </x-filament::section>
                @endif
                @if ($card['cluster'])
                    <x-filament::section :heading="__('gshome.listings.duplicates')">
                        @if ($card['soft_match'])
                            <p class="text-warning-600 dark:text-warning-400 mb-2 text-sm">
                                {{ __('gshome.listings.soft_match_self', ['confidence' => $card['soft_match']['confidence']]) }} {{ $card['soft_match']['explanation'] }}
                            </p>
                        @endif
                        <div class="mb-2 hidden gap-6 text-sm font-medium md:grid md:grid-cols-2">
                            <div>{{ __('gshome.listings.duplicates_in_system') }}</div>
                            <div>{{ __('gshome.listings.duplicates_on_source') }}</div>
                        </div>
                        <ul class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($card['cluster'] as $member)
                                <li class="grid gap-3 py-3 md:grid-cols-2 md:gap-6">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span
                                            class="w-full text-xs font-medium text-gray-500 md:hidden"
                                            >{{ __('gshome.listings.duplicates_in_system') }}</span
                                        >
                                        <span>{{ $member['price'] }}</span>
                                        @if ($member['url'])
                                            <a
                                                href="{{ $member['url'] }}"
                                                class="text-primary-600"
                                                >{{ __('gshome.listings.open_listing') }}</a
                                            >
                                        @endif
                                        <x-filament::badge
                                            :color="$member['ownership']['color']"
                                            >{{ $member['ownership']['label'] }}</x-filament::badge
                                        >
                                        @if ($member['ownership']['same_owner'])
                                            <span>{{ __('gshome.ownership.same_owner') }}</span>
                                        @endif
                                        @if ($member['ownership']['company'])
                                            <span
                                                >{{ __('gshome.listings.company_line', ['name' => $member['ownership']['company']]) }}</span
                                            >
                                        @endif
                                        @if ($member['ownership']['name'])
                                            <span>{{ $member['ownership']['name'] }}</span>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span
                                            class="w-full text-xs font-medium text-gray-500 md:hidden"
                                            >{{ __('gshome.listings.duplicates_on_source') }}</span
                                        >
                                        @if ($member['source_url'])
                                            <a
                                                href="{{ $member['source_url'] }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="text-primary-600"
                                                >{{ $member['source'] }}</a
                                            >
                                        @else
                                            <span>{{ $member['source'] }}</span>
                                        @endif
                                        <span>{{ $member['seller'] }}</span>
                                        @if ($member['soft_match'])
                                            <x-filament::badge
                                                color="warning"
                                                :tooltip="$member['soft_match']['explanation']"
                                                >{{ $member['soft_match']['label'] }}</x-filament::badge
                                            >
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-filament::section>
                @endif
            </div>
            @if ($card['additional'] !== [])
                <x-filament::section :heading="__('gshome.listings.additional')">
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($card['additional'] as $item)
                            <li
                                class="inline-flex min-h-11 items-center gap-2 rounded-full bg-gray-100 px-3 text-sm text-gray-800 dark:bg-white/10 dark:text-gray-100"
                            >
                                <x-filament::icon :icon="$item['icon']" class="size-4 shrink-0" />
                                <span>{{ $item['label'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
