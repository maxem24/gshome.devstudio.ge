<?php

namespace App\Models;

use App\Access\OrgAccess;
use App\Crm\Archive;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Задача сотрудника: что сделать и к какому сроку.
 *
 * @property int $assignee_id
 * @property int $author_id
 * @property string $body
 * @property string $status
 * @property ?Carbon $due_at
 */
#[Fillable(['body', 'assignee_id', 'author_id', 'due_at', 'status'])]
class Task extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'assignee_id' => 'integer',
            'author_id' => 'integer',
            'due_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Задачи тех, кого видит человек по ступени; архив — по правилу Archive.
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        $query->whereIn('assignee_id', OrgAccess::visibleUsers($viewer)->select('users.id'));

        return Archive::scope($query, $viewer);
    }
}
