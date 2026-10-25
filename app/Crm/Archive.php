<?php

namespace App\Crm;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Архив (мягкое удаление) виден только владельцу группы и только по его переключателю. */
final class Archive
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  запрос по модели с SoftDeletes
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, User $viewer): Builder
    {
        if ($viewer->role === Role::Owner && $viewer->show_archived) {
            // @phpstan-ignore method.notFound (SoftDeletes добавляет withTrashed макросом)
            return $query->withTrashed();
        }

        return $query;
    }
}
