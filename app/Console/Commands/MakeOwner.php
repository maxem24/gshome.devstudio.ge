<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** Первый владелец группы: регистрации нет, всех остальных он заводит сам. */
class MakeOwner extends Command
{
    protected $signature = 'gshome:owner {--name=} {--email=} {--password=}';

    protected $description = 'Создать владельца группы компаний';

    public function handle(): int
    {
        $name = $this->option('name') ?? text('Имя', required: true);
        $email = Str::lower(trim($this->option('email') ?? text('Email', required: true)));
        $password = $this->option('password') ?? password('Пароль (минимум 8 символов)', required: true);

        if (mb_strlen($password) < 8) {
            $this->error('Пароль короче 8 символов.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("Человек с email {$email} уже есть.");

            return self::FAILURE;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => Role::Owner,
            'status' => UserStatus::Active,
        ]);

        $this->info("Владелец {$email} создан.");

        return self::SUCCESS;
    }
}
