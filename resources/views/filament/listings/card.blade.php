{{-- Плитка объявления в сетке «Объектов» и результата поиска: фото — квадрат, чтобы квартиру было видно; дальше цена и параметры. --}}
@php ($record = $getRecord())
<div class="flex w-full flex-col gap-3">
    <div
        class="relative aspect-square w-full overflow-hidden rounded-lg bg-gray-100 dark:bg-white/5"
    >
        @if ($record['image'])
            <img
                src="{{ $record['image'] }}"
                alt=""
                loading="lazy"
                class="h-full w-full object-cover"
            />
        @else
            <div
                class="flex h-full w-full items-center justify-center text-gray-400 dark:text-gray-600"
            >
                <x-filament::icon icon="heroicon-o-photo" class="h-12 w-12" />
            </div>
        @endif
        {{-- На фото метки читаются только на плотной подложке. --}}
        <div class="absolute start-2 top-2 flex flex-wrap gap-1 text-xs font-medium">
            @if ($record['direction'])
                <span
                    class="rounded-md bg-white/90 px-2 py-0.5 text-gray-950 shadow-sm dark:bg-gray-950/80 dark:text-white"
                    >{{ $record['direction'] }}</span
                >
            @endif
            @if ($record['partial'] ?? false)
                <span
                    class="rounded-md bg-amber-400/95 px-2 py-0.5 text-amber-950 shadow-sm"
                    >{{ __('gshome.search.card_partial') }}</span
                >
            @endif
        </div>
    </div>

    <div class="flex items-baseline justify-between gap-2">
        <span
            class="text-xl font-bold tracking-tight text-gray-950 dark:text-white"
            >{{ $record['price'] }}</span
        >
        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
            {{ $record['source'] }}
            @if ($record['created_at'])
                · {{ $record['created_at'] }}
            @endif
        </span>
    </div>

    @if ($record['facts'])
        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $record['facts'] }}</p>
    @endif

    @if ($record['district'] || $record['address'])
        <p class="line-clamp-1 text-sm text-gray-500 dark:text-gray-400">
            {{ collect([$record['district'], $record['address']])->filter()->implode(' · ') }}
        </p>
    @endif

    <p
        class="line-clamp-2 text-xs text-gray-400 dark:text-gray-500"
        title="{{ $record['title'] }}"
    >{{ $record['title'] }}</p>

    <div class="flex flex-wrap items-center gap-2">
        <x-filament::badge
            :color="$record['ownership_color']"
            size="sm"
            >{{ $record['ownership_label'] }}</x-filament::badge
        >
    </div>
    @if ($record['ownership_notes'])
        <div class="text-sm text-gray-600 dark:text-gray-300">
            {{-- Строки уже экранированы в ListingRows::notes(); чистим ещё раз, как делал TextColumn::html(). --}}
            {{ str($record['ownership_notes'])->sanitizeHtml()->toHtmlString() }}
        </div>
    @endif
</div>
