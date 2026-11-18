{{-- Подбор пар: $title, $rows (MatchRows) --}}
<x-filament::section>
    <x-slot name="heading">{{ $title }}</x-slot>
    @if ($rows === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('gshome.crm.matching.empty') }}</p>
    @else
        <ul class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($rows as $row)
                <li>
                    <a href="{{ $row['url'] }}" class="flex items-start gap-3 py-2.5 hover:bg-gray-50 dark:hover:bg-white/5">
                        <span @class([
                            'w-12 shrink-0 text-right text-sm font-semibold tabular-nums',
                            'text-success-600 dark:text-success-400' => $row['enough'] && ($row['score'] ?? 0) >= 80,
                            'text-gray-950 dark:text-white' => $row['enough'] && ($row['score'] ?? 0) < 80,
                            'text-gray-400 dark:text-gray-500' => ! $row['enough'],
                        ])>{{ $row['score'] === null ? '—' : $row['score'].'%' }}</span>
                        <span class="min-w-0 text-sm">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</span>
                            @if ($row['sub'])
                                <span class="text-gray-500 dark:text-gray-400"> · {{ $row['sub'] }}</span>
                            @endif
                            @if (! $row['enough'] || $row['mismatches'] !== [])
                                <span class="block text-gray-500 dark:text-gray-400">
                                    {{ implode(' · ', array_merge($row['enough'] ? [] : [__('gshome.crm.matching.little_data')], $row['mismatches'])) }}
                                </span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
