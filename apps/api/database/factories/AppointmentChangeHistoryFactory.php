<?php

namespace Database\Factories;

use App\Models\AppointmentChangeHistory;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentChangeHistory>
 */
class AppointmentChangeHistoryFactory extends Factory
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
            'change_type' => 'rescheduled',
            'from_starts_at' => now()->addDay(),
            'from_ends_at' => now()->addDay()->addMinutes(30),
            'to_starts_at' => now()->addDays(2),
            'to_ends_at' => now()->addDays(2)->addMinutes(30),
            'reason' => 'Reprogramación sintética.',
            'occurred_at' => now(),
        ];
    }
}
