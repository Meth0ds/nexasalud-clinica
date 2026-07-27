<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPreference>
 */
class UserPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'key' => fake()->unique()->randomElement(['theme', 'agenda-density', 'reduced-motion', 'locale']),
            'value' => ['enabled' => true],
            'lock_version' => 1,
        ];
    }
}
