<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/** Язык панели — сохранённый на человеке. Гость остаётся на APP_LOCALE. */
final class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->locale instanceof Locale) {
            app()->setLocale($user->locale->value);
            Carbon::setLocale($user->locale->value);
        }

        return $next($request);
    }
}
