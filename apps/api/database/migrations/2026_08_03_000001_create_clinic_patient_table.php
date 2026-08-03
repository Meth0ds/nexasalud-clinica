<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the patient-to-clinic relationship used to prevent cross-clinic record exposure.
     */
    public function up(): void
    {
        Schema::create('clinic_patient', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id');
            $table->foreignUlid('clinic_id');
            $table->foreignUlid('patient_id');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'clinic_id', 'patient_id']);
            $table->index(['clinic_id', 'is_active', 'created_at']);
            $table->index(['patient_id', 'is_active']);
            $table->foreign(['tenant_id', 'clinic_id'])
                ->references(['tenant_id', 'id'])
                ->on('clinics')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->cascadeOnDelete();
        });

        $timestamp = now();
        $sourceTables = [
            'appointments',
            'prescriptions',
            'invoices',
            'waitlist_entries',
        ];

        foreach ($sourceTables as $sourceTable) {
            if (! Schema::hasTable($sourceTable) || ! Schema::hasColumn($sourceTable, 'clinic_id')) {
                continue;
            }

            DB::table($sourceTable)
                ->whereNotNull('clinic_id')
                ->select(['tenant_id', 'clinic_id', 'patient_id'])
                ->distinct()
                ->orderBy('tenant_id')
                ->orderBy('clinic_id')
                ->orderBy('patient_id')
                ->get()
                ->each(function (object $row) use ($timestamp): void {
                    DB::table('clinic_patient')->insertOrIgnore([
                        'id' => (string) str()->ulid(),
                        'tenant_id' => $row->tenant_id,
                        'clinic_id' => $row->clinic_id,
                        'patient_id' => $row->patient_id,
                        'is_active' => true,
                        'first_seen_at' => $timestamp,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                });
        }
    }

    /**
     * Remove patient-clinic links.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_patient');
    }
};
