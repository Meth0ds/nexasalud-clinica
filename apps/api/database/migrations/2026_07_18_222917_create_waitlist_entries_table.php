<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('clinic_id');
            $table->ulid('patient_id');
            $table->ulid('appointment_type_id');
            $table->foreignUlid('preferred_clinician_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('desired_from');
            $table->timestampTz('desired_to');
            $table->json('preferred_days')->nullable();
            $table->unsignedTinyInteger('priority')->default(3);
            $table->enum('status', ['waiting', 'offered', 'booked', 'expired', 'cancelled'])->default('waiting');
            $table->text('notes')->nullable();
            $table->timestampTz('offered_at')->nullable();
            $table->timestampTz('offer_expires_at')->nullable();
            $table->ulid('fulfilled_appointment_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'clinic_id'])->references(['tenant_id', 'id'])->on('clinics')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'appointment_type_id'])->references(['tenant_id', 'id'])->on('appointment_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fulfilled_appointment_id'])->references(['tenant_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'priority', 'desired_from']);
            $table->index(['tenant_id', 'patient_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
