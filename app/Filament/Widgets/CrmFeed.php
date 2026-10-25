<?php

namespace App\Filament\Widgets;

use App\Crm\JournalQuery;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

/** Лента главной: что делают люди компании. Строка — событие, имя агента, номер объекта; клиента в ней нет. */
class CrmFeed extends Widget
{
    protected string $view = 'filament.widgets.crm-feed';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    /** @return list<array{at: string, text: string}> */
    public function lines(): array
    {
        $viewer = Auth::user();
        if (! $viewer instanceof User) {
            return [];
        }

        return JournalQuery::feed($viewer)->get()->map(fn (Activity $a): array => [
            'at' => $a->created_at?->timezone(config('crm.timezone'))->format('d.m H:i') ?? '',
            'text' => __('gshome.crm.feed.'.$a->event, [
                'name' => $a->causer instanceof User ? $a->causer->name : '—',
                'listing' => $a->properties->get('listing_id') ?? '—',
            ]),
        ])->all();
    }
}
