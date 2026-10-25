<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/** Переключатель «Показывать архивное»: только владелец группы, проверка на сервере. */
final class ArchiveToggleController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->role === Role::Owner, 403);

        $user->update(['show_archived' => ! $user->show_archived]);

        return back();
    }
}
