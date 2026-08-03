<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persist the clinic boundary on generated clinical documents.
     */
    public function up(): void
    {
        Schema::table('clinical_documents', function (Blueprint $table): void {
            $table->foreignUlid('clinic_id')->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'clinic_id', 'created_at']);
            $table->foreign(['tenant_id', 'clinic_id'])
                ->references(['tenant_id', 'id'])
                ->on('clinics')
                ->restrictOnDelete();
        });

        DB::table('clinical_documents')
            ->select(['id', 'tenant_id', 'patient_id', 'appointment_id', 'prescription_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $document): void {
                $clinicId = null;

                if (is_string($document->appointment_id)) {
                    $clinicId = DB::table('appointments')
                        ->where('tenant_id', $document->tenant_id)
                        ->where('id', $document->appointment_id)
                        ->value('clinic_id');
                }

                if (! is_string($clinicId) && is_string($document->prescription_id)) {
                    $clinicId = DB::table('prescriptions')
                        ->where('tenant_id', $document->tenant_id)
                        ->where('id', $document->prescription_id)
                        ->value('clinic_id');
                }

                if (! is_string($clinicId)) {
                    $clinicId = DB::table('clinic_patient')
                        ->where('tenant_id', $document->tenant_id)
                        ->where('patient_id', $document->patient_id)
                        ->where('is_active', true)
                        ->orderBy('clinic_id')
                        ->value('clinic_id');
                }

                if (is_string($clinicId)) {
                    DB::table('clinical_documents')
                        ->where('id', $document->id)
                        ->update(['clinic_id' => $clinicId]);
                }
            });
    }

    /**
     * Remove the clinic relationship from documents.
     */
    public function down(): void
    {
        Schema::table('clinical_documents', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'clinic_id']);
            $table->dropIndex(['tenant_id', 'clinic_id', 'created_at']);
            $table->dropColumn('clinic_id');
        });
    }
};
