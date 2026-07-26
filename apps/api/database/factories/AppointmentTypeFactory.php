<?php

namespace Database\Factories;

use App\Models\AppointmentType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentType>
 */
class AppointmentTypeFactory extends Factory
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
            'code' => strtoupper(fake()->unique()->bothify('TYPE-###')),
            'name' => fake()->randomElement(['Primera consulta', 'Seguimiento', 'Revisión programada', 'Teleconsulta']),
            'duration_minutes' => fake()->randomElement([20, 30, 45, 60]),
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 5,
            'color' => '#2F6B5F',
            'requires_room' => true,
            'allows_video' => true,
            'is_active' => true,
        ];
    }
}
