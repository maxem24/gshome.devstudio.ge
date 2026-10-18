<?php

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class LocaleController extends Controller
{
    public function __invoke(string $locale): RedirectResponse
    {
        $chosen = Locale::tryFrom($locale);
        abort_unless($chosen instanceof Locale, 404);

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $user->update(['locale' => $chosen]);

        return back();
    }
}
