<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_slot_holds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('clinic_id');
            $table->ulid('appointment_type_id');
            $table->ulid('patient_id');
            $table->foreignUlid('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('clinician_user_id')->constrained('users')->restrictOnDelete();
            $table->ulid('room_id')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->enum('channel', ['in_person', 'video', 'phone'])->default('in_person');
            $table->enum('status', ['active', 'consumed', 'released', 'expired'])->default('active');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->ulid('appointment_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'clinic_id'])->references(['tenant_id', 'id'])->on('clinics')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'appointment_type_id'])->references(['tenant_id', 'id'])->on('appointment_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'room_id'])->references(['tenant_id', 'id'])->on('rooms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'clinic_id', 'starts_at', 'status']);
            $table->index(['tenant_id', 'created_by_user_id', 'status', 'expires_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE appointment_slot_holds ADD CONSTRAINT appointment_slot_holds_time_order CHECK (ends_at > starts_at)');
            DB::statement("ALTER TABLE appointment_slot_holds ADD CONSTRAINT slot_holds_clinician_no_overlap EXCLUDE USING gist (tenant_id WITH =, clinician_user_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (status = 'active')");
            DB::statement("ALTER TABLE appointment_slot_holds ADD CONSTRAINT slot_holds_patient_no_overlap EXCLUDE USING gist (tenant_id WITH =, patient_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (status = 'active')");
            DB::statement("ALTER TABLE appointment_slot_holds ADD CONSTRAINT slot_holds_room_no_overlap EXCLUDE USING gist (tenant_id WITH =, room_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (room_id IS NOT NULL AND status = 'active')");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE appointment_slot_holds DROP CONSTRAINT IF EXISTS slot_holds_room_no_overlap');
            DB::statement('ALTER TABLE appointment_slot_holds DROP CONSTRAINT IF EXISTS slot_holds_patient_no_overlap');
            DB::statement('ALTER TABLE appointment_slot_holds DROP CONSTRAINT IF EXISTS slot_holds_clinician_no_overlap');
            DB::statement('ALTER TABLE appointment_slot_holds DROP CONSTRAINT IF EXISTS appointment_slot_holds_time_order');
        }

        Schema::dropIfExists('appointment_slot_holds');
    }
};
