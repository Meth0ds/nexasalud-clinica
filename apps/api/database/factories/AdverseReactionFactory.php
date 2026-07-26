<?php

namespace Database\Factories;

use App\Models\AdverseReaction;
use App\Models\Patient;
use App\Models\PatientAllergy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdverseReaction>
 */
class AdverseReactionFactory extends Factory
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
            'patient_allergy_id' => fn (array $attributes): string => PatientAllergy::factory()->create(['tenant_id' => $attributes['tenant_id'], 'patient_id' => $attributes['patient_id']])->getKey(),
            'manifestation_code_system' => 'https://example.test/codes/manifestation',
            'manifestation_code' => 'SYN-REACTION-001',
            'manifestation_display' => 'Manifestación sintética',
            'severity' => 'unknown',
            'notes' => 'Reacción sintética para pruebas.',
            'occurred_at' => now()->subDay(),
        ];
    }
}
