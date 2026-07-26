<?php

namespace Database\Factories;

use App\Models\Insurer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Insurer>
 */
class InsurerFactory extends Factory
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
            'code' => strtoupper(fake()->unique()->bothify('INS-###')),
            'name' => 'Aseguradora Sintética '.fake()->companySuffix(),
            'provider_reference' => 'DEMO-'.fake()->unique()->numerify('######'),
            'is_active' => true,
        ];
    }
}
