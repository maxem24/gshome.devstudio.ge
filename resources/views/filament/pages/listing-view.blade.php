<x-filament-panels::page>
    @if ($error)
        <x-filament::section><p>{{ $error }}</p></x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge
                    :color="$card['own']['color']"
                    >{{ $card['own']['label'] }}</x-filament::badge
                >
                <span
                    >{{ collect([$card['own']['company'], $card['own']['name']])->filter()->implode(' · ') }}</span
                >
                @if ($card['group'])
                    <x-filament::badge :color="$card['group']['color']"
                        >В группе есть наш сотрудник: {{ $card['group']['name'] ?? $card['group']['company'] }}</x-filament::badge
                    >
                @endif
            </div>
        </x-filament::section>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                @if ($card['images'])
                    <x-filament::section heading="Фото">
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
                    <x-filament::section heading="Описание">
                        <p class="whitespace-pre-line">{{ $card['description'] }}</p>
                    </x-filament::section>
                @endif
                @if ($card['cluster'])
                    <x-filament::section heading="Группа дублей">
                        <ul class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($card['cluster'] as $member)
                                <li class="flex flex-wrap items-center gap-3 py-2">
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
                                    <span>{{ $member['price'] }}</span>
                                    <x-filament::badge
                                        :color="$member['ownership']['color']"
                                        >{{ $member['ownership']['label'] }}</x-filament::badge
                                    >
                                    <span
                                        >{{ collect([$member['ownership']['company'], $member['ownership']['name']])->filter()->implode(' · ') }}</span
                                    >
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
                        <p class="text-sm text-gray-500">Торг</p>
                    @endif
                    <p class="mt-2"><x-filament::badge>{{ $card['direction'] }}</x-filament::badge></p>
                </x-filament::section>
                <x-filament::section heading="Характеристики">
                    <dl class="space-y-1 text-sm">
                        <div>{{ $card['facts'] }}</div>
                        <div>{{ $card['district'] }}</div>
                        <div>
                            {{ $card['address'] }}
                            @if ($card['map'])
                                <a
                                    href="{{ $card['map'] }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-primary-600"
                                    >на карте</a
                                >
                            @endif
                        </div>
                        @if ($card['amenities'])
                            <div>{{ implode(', ', $card['amenities']) }}</div>
                        @endif
                        @if ($card['subway'])
                            <div>Метро: {{ implode(', ', $card['subway']) }}</div>
                        @endif
                        <div>
                            Появилось: {{ $card['created_at'] ?? '—' }} · {{ $card['source'] }}
                        </div>
                    </dl>
                </x-filament::section>
                <x-filament::section heading="Продавец">
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
