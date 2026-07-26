<?php

namespace Database\Factories;

use App\Models\AppointmentStatusHistory;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentStatusHistory>
 */
class AppointmentStatusHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'tenant_id' => fn (array $attributes): string => Appointment::withoutGlobalScope('tenant')->findOrFail($attributes['appointment_id'])->tenant_id,
            'from_status' => 'scheduled',
            'to_status' => 'confirmed',
            'reason' => 'Cambio sintético.',
            'context' => ['source' => 'factory'],
            'occurred_at' => now(),
        ];
    }
}
