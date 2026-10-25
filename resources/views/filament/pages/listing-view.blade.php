<x-filament-panels::page>
    @if ($error)
        <x-filament::section><p>{{ $error }}</p></x-filament::section>
    @else
        @php($banner = $this->banner())
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
            </x-filament::section>
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

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                @if ($card['images'])
                    <x-filament::section :heading="__('gshome.listings.photos')">
                        <div class="grid grid-cols-2 gap-2 md:grid-cols-3">
                            @foreach ($card['images'] as $image)
                                <a href="{{ $image['url'] }}" target="_blank" rel="noopener">
                                    <img
                                        src="{{ $image['url_thumb'] ?? $image['url'] }}"
                                        alt=""
                                        class="h-40 w-full rounded-lg object-cover"
                                        loading="lazy"
                                    />
                                </a>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif
                @if ($card['description'])
                    <x-filament::section :heading="__('gshome.listings.description')">
                        <p class="whitespace-pre-line">{{ $card['description'] }}</p>
                    </x-filament::section>
                @endif
                @if ($card['cluster'])
                    <x-filament::section :heading="__('gshome.listings.duplicates')">
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
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-filament::section>
                @endif
            </div>
            <div class="space-y-6">
                <x-filament::section>
                    <p class="text-2xl font-bold">{{ $card['price'] }}</p>
                    @if ($card['price_gel'])
                        <p class="text-gray-500">{{ $card['price_gel'] }}</p>
                    @endif
                    @if ($card['negotiable'])
                        <p class="text-sm text-gray-500">{{ __('gshome.listings.bargain') }}</p>
                    @endif
                    <p class="mt-2"><x-filament::badge>{{ $card['direction'] }}</x-filament::badge></p>
                </x-filament::section>
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
                <x-filament::section :heading="__('gshome.listings.additional')">
                    <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach ($card['additional'] as $item)
                            <li
                                @class ([
                                'flex items-center gap-2 text-sm',
                                'text-green-600' => $item['active'],
                                'text-gray-400' => ! $item['active'],
                            ])
                            >
                                <x-filament::icon :icon="$item['icon']" class="h-5 w-5 shrink-0" />
                                <span>{{ $item['label'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
                <x-filament::section :heading="__('gshome.listings.seller')">
                    <dl class="space-y-1 text-sm">
                        <div>{{ $card['seller'] }}</div>
                        <div>{{ $card['contact_name'] }}</div>
                        <div>
                            {{ $card['contact_phone'] }}
                            @if ($card['viber']) · Viber @endif
                            @if ($card['whatsapp']) · WhatsApp @endif
                        </div>
                    </dl>
                </x-filament::section>
            </div>
        </div>
    @endif
</x-filament-panels::page>
