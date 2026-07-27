<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'tenant_id' => fn (array $attributes): string => Clinic::withoutGlobalScope('tenant')->findOrFail($attributes['clinic_id'])->tenant_id,
            'code' => strtoupper(fake()->unique()->bothify('R-##')),
            'name' => 'Consulta '.fake()->numberBetween(1, 30),
            'capacity' => 1,
            'supports_video' => fake()->boolean(25),
            'is_active' => true,
        ];
    }
}
