<?php

namespace App\Filament\Pages;

use App\Crm\AgentReport;
use App\Crm\AgentReportRow;
use App\Crm\DealStages;
use App\Models\ListingPin;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Отчёт руководителя: цифры по людям за период и список застывших объектов. Только чтение. */
class AgentReportPage extends Page
{
    protected string $view = 'filament.pages.agent-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $slug = 'agent-report';

    protected static ?int $navigationSort = 6;

    /** Период, Y-m-d в часовом поясе CRM; по умолчанию последние 30 дней. */
    public string $from = '';

    public string $until = '';

    /** Чьи застывшие объекты открыты в списке. */
    public ?int $staleFor = null;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('gshome.crm.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('gshome.crm.report.title');
    }

    public function getTitle(): string
    {
        return __('gshome.crm.report.title');
    }

    public function mount(): void
    {
        $today = CarbonImmutable::now(config('crm.timezone'));
        $this->from = $today->subDays(30)->toDateString();
        $this->until = $today->toDateString();
    }

    /** @return list<AgentReportRow> */
    public function rows(): array
    {
        $tz = config('crm.timezone');
        $from = CarbonImmutable::parse($this->from ?: 'today', $tz)->startOfDay();
        $to = CarbonImmutable::parse($this->until ?: 'today', $tz)->endOfDay();

        return app(AgentReport::class)->rows($this->viewer(), $from, $to);
    }

    /** @return array<string, string> ключ этапа ⇒ подпись, по порядку этапов */
    public function stageColumns(): array
    {
        $cols = [];
        foreach (DealStages::all() as $stage) {
            $cols[$stage->key] = DealStages::find($stage->key)?->label() ?? $stage->key;
        }

        return $cols;
    }

    public function showStale(int $userId): void
    {
        // Только тот, кого человек видит в отчёте
        abort_unless(collect($this->rows())->contains(fn (AgentReportRow $r): bool => $r->user->id === $userId), 403);
        $this->staleFor = $this->staleFor === $userId ? null : $userId;
    }

    /** @return list<array{listing_id: int, url: string, holder: string, days: int}> */
    public function staleList(): array
    {
        if ($this->staleFor === null) {
            return [];
        }
        $row = collect($this->rows())->first(fn (AgentReportRow $r): bool => $r->user->id === $this->staleFor);
        if ($row === null) {
            return [];
        }

        return ListingPin::query()->whereIn('id', $row->stalePinIds)->orderBy('last_activity_at')->get()
            ->map(fn (ListingPin $p): array => [
                'listing_id' => $p->listing_id,
                'url' => ListingView::getUrl(['listing' => $p->listing_id]),
                'holder' => $row->user->name,
                'days' => (int) $p->last_activity_at->diffInDays(now(), true),
            ])->all();
    }

    public function duration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        if ($days > 0) {
            return $days.' '.__('gshome.crm.report.unit_d');
        }
        $hours = intdiv($seconds, 3600);
        if ($hours > 0) {
            return $hours.' '.__('gshome.crm.report.unit_h');
        }

        return intdiv($seconds, 60).' '.__('gshome.crm.report.unit_m');
    }

    /** @param array<string, string> $earnings */
    public function money(array $earnings): string
    {
        if ($earnings === []) {
            return '—';
        }

        return collect($earnings)->map(fn (string $sum, string $cur): string => number_format((float) $sum, 0, '.', ' ').' '.$cur)->implode(', ');
    }

    private function viewer(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
