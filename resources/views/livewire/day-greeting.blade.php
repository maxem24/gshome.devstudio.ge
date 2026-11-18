<section
    aria-labelledby="day-greeting-title"
    class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:p-5 dark:bg-gray-900 dark:ring-white/10"
>
    <h2 id="day-greeting-title" class="text-xl font-semibold text-gray-950 dark:text-white">
        {{ $greeting }}
    </h2>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $phrase }}</p>

    <p class="mt-3 text-sm text-gray-950 dark:text-white">
        @if ($summary === null)
            {{ __('gshome.dashboard.nothing_yet') }}
        @else
            {!! $summary !!}
        @endif
    </p>

    <h3 class="mt-4 text-sm font-semibold text-gray-950 dark:text-white">
        {{ __('gshome.dashboard.today_title') }}
    </h3>
    @if ($today === [])
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('gshome.dashboard.today_empty') }}</p>
    @else
        <ul class="mt-1 space-y-1 text-sm">
            @foreach ($today as $item)
                <li class="flex gap-3">
                    <span
                        @class ([
                        'min-w-12 shrink-0 tabular-nums',
                        'text-danger-600 dark:text-danger-400 font-medium' => $item->overdue,
                        'text-gray-500 dark:text-gray-400' => ! $item->overdue,
                    ])
                    >
                        @if ($item->overdue)
                            {{ __('gshome.dashboard.overdue') }}
                        @else
                            {{ $item->at?->timezone($tz)->format('H:i') }}
                        @endif
                    </span>
                    <span class="min-w-0 text-gray-950 dark:text-white">{{ $item->title }}</span>
                </li>
            @endforeach
        </ul>
        <p class="mt-2 text-sm">
            @if ($more > 0)
                <span class="text-gray-500 dark:text-gray-400"
                    >{{ __('gshome.dashboard.today_more', ['count' => $more]) }} ·</span
                >
            @endif
            <a
                href="{{ $agendaUrl }}"
                class="text-primary-600 dark:text-primary-400 font-medium hover:underline"
                >{{ __('gshome.dashboard.all_tasks') }}</a
            >
        </p>
    @endif
</section>
