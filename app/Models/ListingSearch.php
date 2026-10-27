<?php

namespace App\Models;

use App\Access\OrgAccess;
use App\Search\FiltersSummary;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Поиск по сайтам, запущенный человеком. Копий карточек не храним — только id найденных.
 *
 * @property int $user_id
 * @property array<string, mixed> $filters
 * @property string $body_hash
 * @property ?string $remote_id
 * @property string $status queued|running|done|failed|cancelled|timed_out
 * @property ?string $message
 * @property array<string, array<string, mixed>>|null $sites
 * @property int $found
 * @property bool $truncated
 * @property list<int>|null $listing_ids
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $notified_at
 */
#[Fillable(['user_id', 'filters', 'body_hash', 'remote_id', 'status', 'message', 'sites', 'found', 'truncated', 'listing_ids', 'started_at', 'finished_at', 'notified_at'])]
class ListingSearch extends Model
{
    use SoftDeletes;

    public const RUNNING = ['queued', 'running'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'sites' => 'array',
            'listing_ids' => 'array',
            'truncated' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Сколько объявлений прошло наши фильтры (≤100) — это число видно на странице результата. `found` — до фильтров, на сайтах. */
    public function shown(): int
    {
        return count($this->listing_ids ?? []);
    }

    /** Подпись «найдено на сайтах: N» (+ «показаны M самых свежих», если выдача обрезана). */
    public function foundOnSitesNote(?string $locale = null): string
    {
        return $this->truncated
            ? __('gshome.search.found_on_sites_truncated', ['found' => $this->found, 'shown' => $this->shown()], $locale)
            : __('gshome.search.found_on_sites', ['found' => $this->found], $locale);
    }

    /** Сайты что-то нашли, но ни одно объявление не прошло наши фильтры. */
    public function zeroButFound(): bool
    {
        return $this->status === 'done' && $this->shown() === 0 && $this->found > 0;
    }

    public function zeroButFoundNote(?string $locale = null): string
    {
        return __('gshome.search.zero_but_found', [
            'found' => $this->found,
            'filters' => FiltersSummary::make($this->filters, $this->user, $locale),
        ], $locale);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    /** Готов, но часть подходящих сайтов не дошла до конца; skipped — не сбой, сайт просто не подошёл. */
    public function isPartial(): bool
    {
        if ($this->status !== 'done') {
            return false;
        }
        foreach ($this->sites ?? [] as $site) {
            if (! in_array($site['status'] ?? '', ['done', 'skipped'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  Builder<ListingSearch>  $query */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->whereIn('user_id', OrgAccess::visibleUsers($viewer)->select('users.id'));
    }
}
