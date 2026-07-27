<?php

namespace Database\Factories;

use App\Models\ClinicalDocument;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClinicalDocument>
 */
class ClinicalDocumentFactory extends Factory
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
            'document_type' => 'synthetic_clinical_summary',
            'status' => 'ready',
            'sha256' => hash('sha256', 'synthetic-document-content-'.fake()->unique()->uuid()),
            'template_version' => 'demo-v1',
            'storage_key' => 'clinical/'.Str::ulid().'/document.pdf.enc',
            'metadata' => ['content_type' => 'application/pdf', 'synthetic' => true],
            'revision' => 1,
            'generated_by' => User::factory(),
            'generated_at' => now(),
        ];
    }
}
