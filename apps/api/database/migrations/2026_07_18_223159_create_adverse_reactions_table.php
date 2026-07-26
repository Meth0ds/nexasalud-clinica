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
        Schema::create('adverse_reactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('patient_id');
            $table->ulid('patient_allergy_id')->nullable();
            $table->string('manifestation_code_system', 80)->nullable();
            $table->string('manifestation_code', 100)->nullable();
            $table->text('manifestation_display');
            $table->enum('severity', ['mild', 'moderate', 'severe', 'unknown'])->default('unknown');
            $table->text('notes')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'patient_allergy_id'])->references(['tenant_id', 'id'])->on('patient_allergies')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adverse_reactions');
    }
};
