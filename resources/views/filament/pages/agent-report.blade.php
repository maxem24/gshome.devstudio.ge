<x-filament-panels::page>
    @php ($rows = $this->rows())
    @php ($stages = $this->stageColumns())

    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label
                class="mb-1 block text-sm font-medium"
                for="report-from"
                >{{ __('gshome.crm.report.from') }}</label
            >
            <x-filament::input.wrapper>
                <x-filament::input type="date" id="report-from" wire:model.live="from" />
            </x-filament::input.wrapper>
        </div>
        <div>
            <label
                class="mb-1 block text-sm font-medium"
                for="report-until"
                >{{ __('gshome.crm.report.until') }}</label
            >
            <x-filament::input.wrapper>
                <x-filament::input type="date" id="report-until" wire:model.live="until" />
            </x-filament::input.wrapper>
        </div>
    </div>

    @if ($rows === [])
        <x-filament::section><p>{{ __('gshome.crm.report.empty') }}</p></x-filament::section>
    @else
        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="border-b text-start">
                            <th class="p-2 text-start">{{ __('gshome.crm.report.agent') }}</th>
                            <th class="p-2 text-end">{{ __('gshome.crm.report.pins_active') }}</th>
                            <th class="p-2 text-end">{{ __('gshome.crm.report.deals_opened') }}</th>
                            <th class="p-2 text-end">
                                {{ __('gshome.crm.report.viewings_held') }}
                            </th>
                            <th class="p-2 text-end">{{ __('gshome.crm.report.won') }}</th>
                            <th class="p-2 text-end">{{ __('gshome.crm.report.lost') }}</th>
                            <th class="p-2 text-end">{{ __('gshome.crm.report.earnings') }}</th>
                            @foreach ($stages as $label)
                                <th
                                    class="p-2 text-end"
                                    title="{{ __('gshome.crm.report.avg_stage') }}"
                                >
                                    {{ $label }}
                                </th>
                            @endforeach
                            <th class="p-2 text-end">{{ __('gshome.crm.report.stale') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr
                                wire:key="report-{{ $row->user->id }}"
                                @class (['border-b', 'bg-danger-50 text-danger-700 dark:bg-danger-950 dark:text-danger-400' => $row->stalePins > 0])
                            >
                                <td class="p-2 font-medium">{{ $row->user->name }}</td>
                                <td class="p-2 text-end">{{ $row->pinsActive }}</td>
                                <td class="p-2 text-end">{{ $row->dealsOpened }}</td>
                                <td class="p-2 text-end">{{ $row->viewingsHeld }}</td>
                                <td class="p-2 text-end">{{ $row->dealsWon }}</td>
                                <td class="p-2 text-end">{{ $row->dealsLost }}</td>
                                <td class="p-2 text-end">{{ $this->money($row->earnings) }}</td>
                                @foreach ($stages as $key => $label)
                                    <td class="p-2 text-end">
                                        {{ isset($row->avgStageSeconds[$key]) ? $this->duration($row->avgStageSeconds[$key]) : '—' }}
                                    </td>
                                @endforeach
                                <td class="p-2 text-end">
                                    @if ($row->stalePins > 0)
                                        <button
                                            type="button"
                                            class="font-bold underline"
                                            wire:click="showStale({{ $row->user->id }})"
                                        >
                                            {{ $row->stalePins }}
                                        </button>
                                    @else
                                        0
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    @if ($this->staleFor !== null)
        <x-filament::section :heading="__('gshome.crm.report.stale_title')">
            @php ($stale = $this->staleList())
            @if ($stale === [])
                <p>{{ __('gshome.crm.report.stale_none') }}</p>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($stale as $item)
                        <li>
                            <a
                                href="{{ $item['url'] }}"
                                class="text-primary-600 font-bold"
                                >{{ __('gshome.crm.report.stale_listing', ['id' => $item['listing_id']]) }}</a
                            >
                            · {{ __('gshome.crm.report.holder') }}: {{ $item['holder'] }} · {{ __('gshome.crm.report.stale_days') }}: {{ $item['days'] }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
