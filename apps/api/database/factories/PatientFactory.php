<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\ClinicPatient;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Link synthetic patients to all existing clinics in their tenant for isolated test requests.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Patient $patient): void {
            if (! app()->environment('testing') || ! Schema::hasTable('clinic_patient')) {
                return;
            }

            $patient->loadMissing('tenant');

            $patient->tenant->clinics()
                ->withoutGlobalScope('tenant')
                ->where('is_active', true)
                ->get()
                ->each(function ($clinic) use ($patient): void {
                    ClinicPatient::query()->updateOrCreate(
                        [
                            'tenant_id' => $patient->tenant_id,
                            'clinic_id' => $clinic->getKey(),
                            'patient_id' => $patient->getKey(),
                        ],
                        ['is_active' => true, 'first_seen_at' => now()],
                    );
                });
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $medicalRecordNumber = 'SYN-'.fake()->unique()->numerify('######');

        return [
            'tenant_id' => Tenant::factory(),
            'medical_record_hash' => str_repeat('0', 64),
            'medical_record_number' => $medicalRecordNumber,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-85 years', '-18 years')->format('Y-m-d'),
            'email' => fake()->safeEmail(),
            'phone' => '+34 600 '.fake()->numerify('### ###'),
            'address' => fake()->streetAddress(),
            'clinical_notes' => 'Nota clínica totalmente sintética para pruebas.',
            'preferred_language' => 'es',
            'is_active' => true,
        ];
    }
}
