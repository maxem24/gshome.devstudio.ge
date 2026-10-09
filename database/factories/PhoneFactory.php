<?php

namespace Database\Factories;

use App\Enums\PhoneKind;
use App\Models\Company;
use App\Models\Phone;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Phone> */
class PhoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numerify('+995555######'),
            'kind' => PhoneKind::Personal,
            'company_id' => Company::factory(),
            'user_id' => null,
        ];
    }

    public function office(): static
    {
        return $this->state(fn (): array => ['kind' => PhoneKind::Office]);
    }

    public function heldBy(User $user): static
    {
        return $this->state(fn (): array => ['company_id' => $user->company_id, 'user_id' => $user->id]);
    }
}
