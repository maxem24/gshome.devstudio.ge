<?php

namespace App\Filament\Pages;

use App\Crm\Agenda;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Models\Viewing;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Мои задачи: открытые задачи и показы без итога по времени; показ ведёт в сделку. */
class MyAgenda extends Page
{
    protected string $view = 'filament.pages.my-agenda';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $slug = 'my-agenda';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('gshome.crm.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('gshome.crm.agenda.title');
    }

    public function getTitle(): string
    {
        return __('gshome.crm.agenda.title');
    }

    /** @return list<array{type: string, id: int, title: string, at: ?string, overdue: bool, url: ?string}> */
    public function rows(): array
    {
        $items = app(Agenda::class)->for($this->viewer());
        $deals = Viewing::query()
            ->whereIn('id', $items->where('type', 'viewing')->pluck('id'))
            ->pluck('deal_id', 'id');
        $tz = (string) config('crm.timezone');

        return $items->map(fn ($i): array => [
            'type' => $i->type,
            'id' => $i->id,
            'title' => $i->title,
            'at' => $i->at?->copy()->timezone($tz)->format('d.m.Y H:i'),
            'overdue' => $i->overdue,
            'url' => $i->type === 'viewing' && $deals->has($i->id)
                ? DealResource::getUrl('view', ['record' => $deals[$i->id]])
                : null,
        ])->all();
    }

    /** Кнопка статуса: задачу берём по id, право проверяется заново — чужая даёт 403 и ничего не меняет. */
    public function setStatus(int $taskId, string $status): void
    {
        TaskResource::applyStatus(Task::query()->findOrFail($taskId), $status);
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
