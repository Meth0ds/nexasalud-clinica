<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
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
