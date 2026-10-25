<?php

namespace App\Filament;

use App\Enums\Locale;
use App\Enums\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/** Три языка в меню аккаунта. Текущий отмечен галочкой; выбор пишется на человека. */
final class LocaleMenu
{
    /** @return array<string, Action> */
    public static function items(): array
    {
        $items = [];

        foreach (Locale::cases() as $index => $locale) {
            $items['locale-'.$locale->value] = Action::make('locale-'.$locale->value)
                ->label($locale->nativeLabel())
                ->icon(fn (): Heroicon => app()->getLocale() === $locale->value ? Heroicon::OutlinedCheck : Heroicon::OutlinedLanguage)
                ->url(fn (): string => route('locale.switch', ['locale' => $locale->value]))
                ->postToUrl()
                ->sort(10 + $index);
        }

        // Архив видит только владелец группы; сервер проверяет роль ещё раз.
        $items['archive-toggle'] = Action::make('archive-toggle')
            ->label(fn (): string => __(Auth::user()?->show_archived ? 'gshome.crm.archive_toggle.hide' : 'gshome.crm.archive_toggle.show'))
            ->icon(fn (): Heroicon => Auth::user()?->show_archived ? Heroicon::OutlinedEyeSlash : Heroicon::OutlinedArchiveBox)
            ->url(fn (): string => route('archive.toggle'))
            ->postToUrl()
            ->visible(fn (): bool => Auth::user() instanceof User && Auth::user()->role === Role::Owner)
            ->sort(5);

        return $items;
    }
}
