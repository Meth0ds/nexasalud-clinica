<?php

namespace Database\Factories;

use App\Models\ConsentRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentRecord>
 */
class ConsentRecordFactory extends Factory
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
            'consent_type' => 'synthetic_data_processing',
            'document_version' => 'demo-v1',
            'status' => 'active',
            'recorded_by' => User::factory(),
            'granted_at' => now(),
            'evidence_hash' => hash('sha256', 'synthetic-consent-evidence-'.fake()->unique()->uuid()),
        ];
    }
}
