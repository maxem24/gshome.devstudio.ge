{{-- Скрытое действие Filament рисует неактивной кнопкой: пока Real Estate ссылки не принимает, кнопки нет вовсе. --}}
<div>
    @if ($this->linkAction->isVisible())
        {{ $this->linkAction }}

        <x-filament-actions::modals />
    @endif
</div>
