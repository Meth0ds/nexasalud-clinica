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
        Schema::create('appointments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('clinic_id');
            $table->ulid('room_id')->nullable();
            $table->ulid('appointment_type_id');
            $table->ulid('patient_id');
            $table->foreignUlid('clinician_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->enum('status', ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'])->default('scheduled');
            $table->enum('channel', ['in_person', 'video', 'phone'])->default('in_person');
            $table->text('reason')->nullable();
            $table->text('clinical_notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'clinic_id'])->references(['tenant_id', 'id'])->on('clinics')->restrictOnDelete();
            $table->foreign(['tenant_id', 'room_id'])->references(['tenant_id', 'id'])->on('rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'appointment_type_id'])->references(['tenant_id', 'id'])->on('appointment_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
            $table->index(['tenant_id', 'clinician_user_id', 'starts_at']);
            $table->index(['tenant_id', 'room_id', 'starts_at']);
            $table->index(['tenant_id', 'patient_id', 'starts_at']);
            $table->index(['tenant_id', 'status', 'starts_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_time_order CHECK (ends_at > starts_at)");
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_patient_no_overlap EXCLUDE USING gist (tenant_id WITH =, patient_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (deleted_at IS NULL AND status IN ('scheduled', 'confirmed', 'checked_in', 'in_progress'))");
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_clinician_no_overlap EXCLUDE USING gist (tenant_id WITH =, clinician_user_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (deleted_at IS NULL AND status IN ('scheduled', 'confirmed', 'checked_in', 'in_progress'))");
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_room_no_overlap EXCLUDE USING gist (tenant_id WITH =, room_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (room_id IS NOT NULL AND deleted_at IS NULL AND status IN ('scheduled', 'confirmed', 'checked_in', 'in_progress'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_room_no_overlap');
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_clinician_no_overlap');
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_patient_no_overlap');
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_time_order');
        }

        Schema::dropIfExists('appointments');
    }
};
