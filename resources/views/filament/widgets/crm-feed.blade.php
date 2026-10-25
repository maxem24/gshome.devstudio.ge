<x-filament-widgets::widget>
    <x-filament::section :heading="__('gshome.crm.feed.title')">
        @forelse ($this->lines() as $line)
            <p class="text-sm"><span class="text-gray-500">{{ $line['at'] }}</span> {{ $line['text'] }}</p>
        @empty
            <p class="text-sm text-gray-500">{{ __('gshome.crm.feed.empty') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
