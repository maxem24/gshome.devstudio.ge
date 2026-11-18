<?php

namespace App\Livewire;

use App\Crm\DaySummary;
use App\Filament\Pages\MyAgenda;
use App\Filament\Pages\MyObjects;
use App\Filament\Resources\Deals\DealResource;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** Приветствие на инфопанели: «Добрый день, Нино!», что в работе и дела на сегодня. */
final class DayGreeting extends Component
{
    public function render(): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $sum = app(DaySummary::class)->for($user);

        // Нулевые числа не пишем: «0 квартир в работе» звучит как упрёк.
        $parts = array_values(array_filter([
            $sum['pins'] > 0 ? ['text' => trans_choice('gshome.dashboard.pins', $sum['pins']), 'url' => MyObjects::getUrl()] : null,
            $sum['clients'] > 0 ? ['text' => trans_choice('gshome.dashboard.clients', $sum['clients']), 'url' => DealResource::getUrl('index')] : null,
            $sum['tasks'] > 0 ? ['text' => trans_choice('gshome.dashboard.tasks', $sum['tasks']), 'url' => MyAgenda::getUrl()] : null,
        ]));

        // Фраза собирается здесь: в шаблоне переносы строк дают пробел перед точкой. Каждая часть экранирована.
        $links = array_map(
            fn (array $p): string => '<a href="'.e($p['url']).'" class="text-primary-600 dark:text-primary-400 font-medium hover:underline">'.e($p['text']).'</a>',
            $parts,
        );
        $last = array_pop($links);
        $summary = $last === null ? null : e(__('gshome.dashboard.summary_lead')).' '
            .($links === [] ? $last : implode(', ', $links).' '.e(__('gshome.dashboard.and')).' '.$last).'.';

        return view('livewire.day-greeting', [
            'summary' => $summary,
            'greeting' => __('gshome.dashboard.greeting.'.$sum['greeting'], ['name' => strtok(trim($user->name), ' ') ?: $user->name]),
            'phrase' => __('gshome.dashboard.phrases.'.$sum['phrase']),
            'today' => $sum['today'],
            'more' => $sum['today_more'],
            'agendaUrl' => MyAgenda::getUrl(),
            'tz' => (string) config('crm.timezone'),
        ]);
    }
}
