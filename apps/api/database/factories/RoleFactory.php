<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
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
            'code' => fake()->unique()->slug(2),
            'name' => fake()->randomElement(['Administración', 'Profesional clínico', 'Recepción', 'Auditoría']),
            'permissions' => ['appointments.read', 'patients.read'],
            'is_system' => false,
        ];
    }
}
