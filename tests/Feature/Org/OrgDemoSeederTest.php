<?php

namespace Tests\Feature\Org;

use App\Enums\Role;
use App\Models\Company;
use App\Models\CompanyExchange;
use App\Models\Phone;
use App\Models\User;
use Database\Seeders\OrgDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_full_structure(): void
    {
        $this->seed(OrgDemoSeeder::class);

        $this->assertSame(2, Company::query()->count());
        $this->assertSame(1, CompanyExchange::query()->where('enabled', true)->count());
        $this->assertSame(2, User::query()->where('role', Role::CompanyOwner)->count());
        // 2 компании × 2 команды × 2 тимлида
        $this->assertSame(8, User::query()->where('role', Role::TeamLead)->count());
        $this->assertSame(16, User::query()->where('role', Role::Agent)->count());
        $this->assertSame(2, Phone::query()->where('kind', 'office')->count());
        $this->assertSame(0, User::query()->where('role', Role::Agent)->whereNull('team_lead_id')->count());
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        // Напрямую, не через $this->seed(): db:seed в production сначала
        // спрашивает подтверждение и до сидера не доходит.
        $this->expectException(\RuntimeException::class);
        $this->app->make(OrgDemoSeeder::class)->run();
    }
}
