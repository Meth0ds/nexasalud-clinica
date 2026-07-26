<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('clinic_id');
            $table->ulid('patient_id');
            $table->foreignUlid('prescriber_user_id')->constrained('users')->restrictOnDelete();
            $table->ulid('appointment_id')->nullable();
            $table->enum('status', ['draft', 'pending_review', 'signed', 'sent', 'cancelled'])->default('draft');
            $table->string('external_provider_reference', 160)->nullable();
            $table->text('clinical_indication')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'clinic_id'])->references(['tenant_id', 'id'])->on('clinics')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id', 'created_at']);
            $table->index(['tenant_id', 'prescriber_user_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_signature_state CHECK ((status NOT IN ('signed', 'sent')) OR signed_at IS NOT NULL)");
            DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_sent_state CHECK ((status <> 'sent') OR sent_at IS NOT NULL)");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE prescriptions DROP CONSTRAINT IF EXISTS prescriptions_sent_state');
            DB::statement('ALTER TABLE prescriptions DROP CONSTRAINT IF EXISTS prescriptions_signature_state');
        }

        Schema::dropIfExists('prescriptions');
    }
};
