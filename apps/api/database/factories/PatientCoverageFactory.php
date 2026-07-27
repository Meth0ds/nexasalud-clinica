<?php

namespace Database\Factories;

use App\Models\Insurer;
use App\Models\Patient;
use App\Models\PatientCoverage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientCoverage>
 */
class PatientCoverageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $memberNumber = 'SYN-MEMBER-'.fake()->unique()->numerify('######');

        return [
            'patient_id' => Patient::factory(),
            'tenant_id' => fn (array $attributes): string => Patient::withoutGlobalScope('tenant')->findOrFail($attributes['patient_id'])->tenant_id,
            'insurer_id' => fn (array $attributes): string => Insurer::factory()->create(['tenant_id' => $attributes['tenant_id']])->getKey(),
            'member_number_hash' => str_repeat('0', 64),
            'member_number' => $memberNumber,
            'policy_number' => 'SYN-POLICY-'.fake()->numerify('######'),
            'valid_from' => now()->startOfYear()->toDateString(),
            'valid_until' => now()->addYear()->endOfYear()->toDateString(),
            'is_primary' => true,
            'is_active' => true,
        ];
    }
}
