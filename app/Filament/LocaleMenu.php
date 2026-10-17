<?php

namespace App\Filament;

use App\Enums\Locale;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

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

        return $items;
    }
}
