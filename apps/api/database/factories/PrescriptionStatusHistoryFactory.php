<?php

namespace Database\Factories;

use App\Models\PrescriptionStatusHistory;
use App\Models\Prescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionStatusHistory>
 */
class PrescriptionStatusHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(),
            'tenant_id' => fn (array $attributes): string => Prescription::withoutGlobalScope('tenant')->findOrFail($attributes['prescription_id'])->tenant_id,
            'from_status' => 'draft',
            'to_status' => 'pending_review',
            'reason' => 'Cambio sintético.',
            'context' => ['source' => 'factory'],
            'occurred_at' => now(),
        ];
    }
}
