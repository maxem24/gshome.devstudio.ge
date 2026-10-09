<?php

namespace Database\Factories;

use App\Enums\Direction;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Owner,
            'status' => UserStatus::Active,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => Role::Owner, 'company_id' => null]);
    }

    public function companyOwner(?Company $company = null): static
    {
        return $this->state(fn (): array => [
            'role' => Role::CompanyOwner,
            'company_id' => $company->id ?? Company::factory(),
        ]);
    }

    public function teamLead(?Company $company = null, Direction $direction = Direction::Sale): static
    {
        return $this->state(fn (): array => [
            'role' => Role::TeamLead,
            'company_id' => $company->id ?? Company::factory(),
            'direction' => $direction,
        ]);
    }

    public function agent(User $lead): static
    {
        return $this->state(fn (): array => [
            'role' => Role::Agent,
            'company_id' => $lead->company_id,
            'direction' => $lead->direction,
            'team_lead_id' => $lead->id,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Blocked]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Archived]);
    }
}
