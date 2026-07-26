<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clinic>
 */
class ClinicFactory extends Factory
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
            'code' => strtoupper(fake()->unique()->bothify('CL-###')),
            'name' => 'Clínica Demo '.fake()->city(),
            'timezone' => 'Europe/Madrid',
            'color' => '#2457E6',
            'is_active' => true,
        ];
    }
}
