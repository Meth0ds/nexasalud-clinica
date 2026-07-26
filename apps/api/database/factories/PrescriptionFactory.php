<?php

namespace Database\Factories;

use App\Models\Prescription;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
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
            'prescriber_user_id' => User::factory(),
            'status' => 'draft',
            'external_provider_reference' => 'SYN-'.fake()->unique()->bothify('########'),
            'clinical_indication' => 'Indicación clínica sintética.',
            'valid_until' => now()->addMonth()->toDateString(),
            'lock_version' => 1,
        ];
    }
}
