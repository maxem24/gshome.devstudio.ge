<x-filament-panels::page>
    @php ($rows = $this->rows())

    @if ($rows === [])
        <x-filament::section><p>{{ __('gshome.crm.agenda.empty') }}</p></x-filament::section>
    @else
        <div class="grid gap-3">
            @foreach ($rows as $row)
                <x-filament::section wire:key="agenda-{{ $row['type'] }}-{{ $row['id'] }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="font-medium">{{ $row['title'] }}</p>
                            <p
                                class="text-sm {{ $row['overdue'] ? 'text-danger-600 dark:text-danger-400' : 'text-gray-500' }}"
                            >
                                {{ $row['at'] ?? __('gshome.crm.agenda.no_due') }}
                                @if ($row['overdue']) ·{{ __('gshome.crm.agenda.overdue') }} @endif
                            </p>
                        </div>
                        <div class="flex gap-2">
                            @if ($row['type'] === 'task')
                                <x-filament::button
                                    size="sm"
                                    color="success"
                                    wire:click="setStatus({{ $row['id'] }}, 'done')"
                                    >{{ __('gshome.crm.tasks.to_done') }}</x-filament::button
                                >
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    wire:click="setStatus({{ $row['id'] }}, 'cancelled')"
                                    >{{ __('gshome.crm.tasks.to_cancelled') }}</x-filament::button
                                >
                            @elseif ($row['url'])
                                <x-filament::button
                                    size="sm"
                                    tag="a"
                                    :href="$row['url']"
                                    >{{ __('gshome.crm.agenda.open_deal') }}</x-filament::button
                                >
                            @endif
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
