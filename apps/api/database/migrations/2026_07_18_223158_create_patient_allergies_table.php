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
        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('patient_id');
            $table->string('substance_code_system', 80)->nullable();
            $table->string('substance_code', 100)->nullable();
            $table->text('substance_display');
            $table->enum('clinical_status', ['active', 'inactive', 'resolved'])->default('active');
            $table->enum('verification_status', ['unconfirmed', 'confirmed', 'refuted'])->default('unconfirmed');
            $table->enum('criticality', ['low', 'high', 'unknown'])->default('unknown');
            $table->text('notes')->nullable();
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('recorded_at')->useCurrent();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id', 'clinical_status']);
            $table->index(['tenant_id', 'substance_code_system', 'substance_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_allergies');
    }
};
