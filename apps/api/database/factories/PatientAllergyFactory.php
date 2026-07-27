<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientAllergy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientAllergy>
 */
class PatientAllergyFactory extends Factory
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
            'substance_code_system' => 'https://example.test/codes/substance',
            'substance_code' => 'SYN-SUBSTANCE-'.fake()->unique()->numerify('####'),
            'substance_display' => 'Sustancia sintética',
            'clinical_status' => 'active',
            'verification_status' => 'confirmed',
            'criticality' => 'unknown',
            'notes' => 'Alergia sintética para pruebas.',
            'recorded_at' => now(),
        ];
    }
}
