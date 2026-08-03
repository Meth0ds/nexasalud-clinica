<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicPatient;
use App\Models\MembershipClinic;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

/**
 * @extends Factory<Clinic>
 */
class ClinicFactory extends Factory
{
    /**
     * Attach pre-existing synthetic staff and patients to a newly-created test clinic.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Clinic $clinic): void {
            if (! app()->environment('testing') || ! Schema::hasTable('membership_clinic')) {
                return;
            }

            $clinic->tenant->memberships()
                ->withoutGlobalScope('tenant')
                ->where('memberships.is_active', true)
                ->get()
                ->each(function ($membership) use ($clinic): void {
                    MembershipClinic::query()->updateOrCreate(
                        [
                            'tenant_id' => $clinic->tenant_id,
                            'membership_id' => $membership->getKey(),
                            'clinic_id' => $clinic->getKey(),
                        ],
                        ['is_active' => true],
                    );
                });

            if (! Schema::hasTable('clinic_patient')) {
                return;
            }

            $clinic->tenant->patients()
                ->withoutGlobalScope('tenant')
                ->where('patients.is_active', true)
                ->get()
                ->each(function ($patient) use ($clinic): void {
                    ClinicPatient::query()->updateOrCreate(
                        [
                            'tenant_id' => $clinic->tenant_id,
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
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('CL-###')),
            'name' => 'Clínica Demo '.fake()->city(),
            'timezone' => 'Europe/Madrid',
            'color' => '#2457E6',
            'is_active' => true,
        ];
    }
}
