<?php

namespace Database\Factories;

use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'tenant_id' => fn (array $attributes): string => Patient::withoutGlobalScope('tenant')->findOrFail($attributes['patient_id'])->tenant_id,
            'clinic_id' => fn (array $attributes): string => Clinic::factory()->create(['tenant_id' => $attributes['tenant_id']])->getKey(),
            'appointment_type_id' => fn (array $attributes): string => AppointmentType::factory()->create(['tenant_id' => $attributes['tenant_id']])->getKey(),
            'desired_from' => now()->addDay(),
            'desired_to' => now()->addMonth(),
            'preferred_days' => [1, 2, 3, 4, 5],
            'priority' => 3,
            'status' => 'waiting',
            'notes' => 'Preferencia sintética.',
            'lock_version' => 1,
        ];
    }
}
