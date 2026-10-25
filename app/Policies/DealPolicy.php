<?php

namespace App\Policies;

use App\Access\OrgAccess;
use App\Crm\Deals;
use App\Enums\DealStatus;
use App\Enums\Role;
use App\Models\Deal;
use App\Models\User;

/**
 * Сделки: видит тот, чья сделка попадает в видимых по ступени (OrgAccess); менять — как Deals::canManage.
 * Создания, правки и удаления из интерфейса нет: сделки открываются с карточки объекта.
 */
class DealPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Deal $deal): bool
    {
        return OrgAccess::visibleUsers($user)->whereKey($deal->user_id)->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Deal $deal): bool
    {
        return false;
    }

    public function delete(User $user, Deal $deal): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Двигать этап, закрывать, назначать показы: живая сделка и право Deals::canManage. */
    public function manage(User $user, Deal $deal): bool
    {
        return ! $deal->trashed() && $this->view($user, $deal) && app(Deals::class)->canManage($deal, $user);
    }

    /** В архив: управляющий, и только закрытую сделку — открытую может убрать лишь владелец группы. */
    public function archive(User $user, Deal $deal): bool
    {
        return $this->manage($user, $deal) && ($deal->status !== DealStatus::Open || $user->role === Role::Owner);
    }
}
