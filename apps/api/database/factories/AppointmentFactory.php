<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+21 days');

        return [
            'patient_id' => Patient::factory(),
            'tenant_id' => fn (array $attributes): string => Patient::withoutGlobalScope('tenant')->findOrFail($attributes['patient_id'])->tenant_id,
            'clinic_id' => fn (array $attributes): string => Clinic::factory()->create(['tenant_id' => $attributes['tenant_id']])->getKey(),
            'room_id' => fn (array $attributes): string => Room::factory()->create(['tenant_id' => $attributes['tenant_id'], 'clinic_id' => $attributes['clinic_id']])->getKey(),
            'appointment_type_id' => fn (array $attributes): string => AppointmentType::factory()->create(['tenant_id' => $attributes['tenant_id']])->getKey(),
            'clinician_user_id' => User::factory(),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+30 minutes'),
            'status' => 'scheduled',
            'channel' => 'in_person',
            'reason' => 'Motivo sintético de consulta.',
            'clinical_notes' => null,
            'lock_version' => 1,
            'created_by' => fn (array $attributes): string => $attributes['clinician_user_id'],
            'updated_by' => fn (array $attributes): string => $attributes['clinician_user_id'],
        ];
    }
}
