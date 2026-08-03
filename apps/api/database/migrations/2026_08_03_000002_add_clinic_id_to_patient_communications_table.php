<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persist the clinic boundary on every patient communication.
     */
    public function up(): void
    {
        Schema::table('patient_communications', function (Blueprint $table): void {
            $table->foreignUlid('clinic_id')->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'clinic_id', 'created_at']);
            $table->foreign(['tenant_id', 'clinic_id'])
                ->references(['tenant_id', 'id'])
                ->on('clinics')
                ->restrictOnDelete();
        });

        DB::table('patient_communications')
            ->select(['id', 'tenant_id', 'patient_id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $communication): void {
                $clinicId = DB::table('clinic_patient')
                    ->where('tenant_id', $communication->tenant_id)
                    ->where('patient_id', $communication->patient_id)
                    ->where('is_active', true)
                    ->orderBy('clinic_id')
                    ->value('clinic_id');

                if (is_string($clinicId)) {
                    DB::table('patient_communications')
                        ->where('id', $communication->id)
                        ->update(['clinic_id' => $clinicId]);
                }
            });
    }

    /**
     * Remove the clinic relationship from communications.
     */
    public function down(): void
    {
        Schema::table('patient_communications', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'clinic_id']);
            $table->dropIndex(['tenant_id', 'clinic_id', 'created_at']);
            $table->dropColumn('clinic_id');
        });
    }
};
