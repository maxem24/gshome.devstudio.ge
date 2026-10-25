<?php

namespace App\Models;

use App\Crm\Archive;
use App\Enums\ClientTag;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['phone_e164', 'first_name', 'last_name', 'tags', 'reliability', 'created_by'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return list<ClientTag> */
    public function tagEnums(): array
    {
        return array_map(ClientTag::from(...), $this->tags ?? []);
    }

    /**
     * База клиентов общая на группу: видимость различает только архив.
     *
     * @param  Builder<Client>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        Archive::scope($query, $viewer);
    }
}
