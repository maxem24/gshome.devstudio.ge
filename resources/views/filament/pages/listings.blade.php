<x-filament-panels::page>
    @if ($company = $this->representedCompany())
        <p class="mb-4 text-sm font-medium">{{ __('gshome.listings.represents', ['name' => $company]) }}</p>
    @endif

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (\App\Enums\ListingScope::cases() as $scope)
            <x-filament::button
                size="sm"
                :color="$this->scope === $scope->value ? 'primary' : 'gray'"
                wire:click="setScope('{{ $scope->value }}')"
                aria-pressed="{{ $this->scope === $scope->value ? 'true' : 'false' }}"
            >
                {{ $scope->getLabel() }}
            </x-filament::button>
        @endforeach
    </div>

    {{ $this->table }}

    <div
        wire:loading.flex
        wire:target="loadMoreListings"
        class="items-center justify-center py-6"
        role="status"
        aria-label="{{ __('gshome.listings.loading_more') }}"
    >
        <x-filament::loading-indicator class="h-8 w-8" />
    </div>

    {{-- Сторож внизу списка: дописывает следующую страницу курсора, пока API её отдаёт. --}}
    <div
        wire:ignore
        x-data="{
            start() {
                const target = this.$el
                const io = new IntersectionObserver((entries) => {
                    if (! entries.some((entry) => entry.isIntersecting) || $wire.noMoreListings) {
                        if ($wire.noMoreListings) {
                            io.disconnect()
                        }
                        return
                    }
                    io.disconnect()
                    $wire.loadMoreListings().then(() => {
                        if (! $wire.noMoreListings) {
                            io.observe(target)
                        }
                    })
                }, { rootMargin: '400px' })
                io.observe(target)
            },
        }"
        x-init="start()"
        class="h-px w-full"
        aria-hidden="true"
    ></div>
</x-filament-panels::page>
