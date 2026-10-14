<x-filament-panels::page>
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
