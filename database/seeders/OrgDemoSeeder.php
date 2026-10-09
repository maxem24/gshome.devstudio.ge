<?php

namespace Database\Seeders;

use App\Enums\Direction;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Демо-структура для локальной работы: docker compose exec app php artisan db:seed --class=OrgDemoSeeder
 * Пароль у всех — password. Почта — плюс-алиасы тестовой личности; из Docker
 * всё равно уходит в Mailpit.
 */
class OrgDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Демо-структура не для прода.');
        }

        $companies = [];
        foreach (['vake' => 'GS Home Vake', 'saburtalo' => 'GS Home Saburtalo'] as $slug => $name) {
            $company = Company::create(['name' => $name]);
            $companies[] = $company;

            $owner = User::factory()->companyOwner($company)->create([
                'name' => "Владелец {$name}",
                'email' => "max+cl.{$slug}.owner@absoluteweb.com",
            ]);
            Phone::factory()->office()->heldBy($owner)->create();

            foreach (Direction::cases() as $direction) {
                foreach ([1, 2] as $n) {
                    $lead = User::factory()->teamLead($company, $direction)->create([
                        'name' => "Тимлид {$direction->getLabel()} {$n} ({$slug})",
                        'email' => "max+cl.{$slug}.{$direction->value}.lead{$n}@absoluteweb.com",
                    ]);
                    Phone::factory()->heldBy($lead)->create();

                    foreach ([1, 2] as $m) {
                        $agent = User::factory()->agent($lead)->create([
                            'name' => "Сотрудник {$m} у тимлида {$n} ({$slug}, {$direction->getLabel()})",
                            'email' => "max+cl.{$slug}.{$direction->value}.lead{$n}.agent{$m}@absoluteweb.com",
                        ]);
                        Phone::factory()->heldBy($agent)->create();
                    }
                }
            }
        }

        CompanyExchange::create([
            'company_a_id' => $companies[0]->id,
            'company_b_id' => $companies[1]->id,
            'enabled' => true,
        ]);
    }
}
