<?php

namespace Database\Factories;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescriptionItem>
 */
class PrescriptionItemFactory extends Factory
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
            'medication_code_system' => 'https://example.test/codes/medication',
            'medication_code' => 'SYN-MED-'.fake()->unique()->numerify('####'),
            'medication_display' => 'Medicamento sintético',
            'dose_amount' => 1,
            'dose_unit_code' => 'unit',
            'route_code' => 'oral',
            'frequency_code' => 'synthetic-frequency',
            'duration_value' => 7,
            'duration_unit' => 'day',
            'quantity' => 7,
            'quantity_unit_code' => 'unit',
            'instructions' => 'Instrucciones totalmente sintéticas; no usar clínicamente.',
            'sort_order' => 0,
        ];
    }
}
