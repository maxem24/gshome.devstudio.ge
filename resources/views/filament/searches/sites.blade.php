{{-- Сайты поиска метками: имя, статус цветом и число найденного; пояснение — в подсказке. --}}
<div class="flex min-w-56 flex-col gap-1 px-3 py-4">
    @foreach (\App\Filament\Pages\MySearches::sites($getRecord()) as $site)
        <div
            class="flex items-center gap-2 text-sm"
            @if ($site['message']) title="{{ $site['message'] }}" @endif
        >
            <span class="w-24 shrink-0 text-gray-950 dark:text-white">{{ $site['name'] }}</span>
            <x-filament::badge
                :color="$site['color']"
                size="sm"
                >{{ $site['label'] }}</x-filament::badge
            >
            <span class="text-gray-500 dark:text-gray-400">{{ $site['found'] }}</span>
        </div>
    @endforeach
</div>
