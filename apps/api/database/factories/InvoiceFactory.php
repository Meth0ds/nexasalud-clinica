<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientCoverage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
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
            'patient_coverage_id' => fn (array $attributes): string => PatientCoverage::factory()->create(['tenant_id' => $attributes['tenant_id'], 'patient_id' => $attributes['patient_id']])->getKey(),
            'invoice_number' => 'SYN-INV-'.fake()->unique()->numerify('########'),
            'status' => 'issued',
            'currency' => 'EUR',
            'subtotal_minor' => 10000,
            'tax_minor' => 0,
            'total_minor' => 10000,
            'balance_minor' => 10000,
            'issued_on' => now()->toDateString(),
            'due_on' => now()->addDays(30)->toDateString(),
            'notes' => 'Factura completamente sintética.',
            'lock_version' => 1,
        ];
    }
}
