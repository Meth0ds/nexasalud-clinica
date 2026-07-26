<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Salud Demo';

        return [
            'slug' => fake()->unique()->slug(2),
            'name' => $name,
            'timezone' => 'Europe/Madrid',
            'locale' => 'es',
            'is_active' => true,
        ];
    }
}
