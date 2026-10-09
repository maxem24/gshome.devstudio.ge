<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Email в базе хранится в нижнем регистре (User::email()), а PostgreSQL
 * сравнивает строки с учётом регистра: без этого «Giorgi@…» не входил бы.
 */
class Login extends BaseLogin
{
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'email' => Str::lower(trim((string) $data['email'])),
            'password' => $data['password'],
        ];
    }
}
