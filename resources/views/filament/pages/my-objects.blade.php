<x-filament-panels::page>
    @php($data = $this->rows())

    @if ($this->canFilterByAgent())
        <div class="max-w-sm">
            <label class="mb-1 block text-sm font-medium" for="agent-filter">{{ __('gshome.crm.my_objects.agent') }}</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="agent-filter" wire:model.live="agent">
                    <option value="">{{ __('gshome.crm.my_objects.all_agents') }}</option>
                    @foreach ($this->agentOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    @endif

    @if ($data['error'])
        <p class="text-danger-600 dark:text-danger-400 text-sm" role="status">{{ $data['error'] }}</p>
    @endif

    @if ($data['rows'] === [])
        <x-filament::section><p>{{ __('gshome.crm.my_objects.empty') }}</p></x-filament::section>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($data['rows'] as $row)
                <x-filament::section wire:key="pin-{{ $row['pin_id'] }}">
                    @if ($row['unavailable'])
                        <p class="font-medium">{{ __('gshome.crm.my_objects.listing_unavailable', ['id' => $row['listing_id']]) }}</p>
                    @else
                        <a href="{{ $row['url'] }}" class="text-primary-600 font-bold">{{ $row['title'] }}</a>
                        <p class="text-lg font-bold">{{ $row['price'] }}</p>
                        <p class="text-sm text-gray-500">{{ $row['address'] }}</p>
                        <p class="text-sm">{{ $row['facts'] }}</p>
                    @endif
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <x-filament::badge>{{ $row['direction'] }}</x-filament::badge>
                        @if ($this->canFilterByAgent())
                            <span class="text-sm">{{ $row['holder'] }}</span>
                        @endif
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
