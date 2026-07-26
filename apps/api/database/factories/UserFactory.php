<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'oidc_issuer' => 'https://identity.example.test/realms/nexasalud-demo',
            'oidc_subject' => (string) Str::uuid(),
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
            'last_login_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
