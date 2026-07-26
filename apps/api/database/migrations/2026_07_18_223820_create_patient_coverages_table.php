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
        Schema::create('patient_coverages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('patient_id');
            $table->ulid('insurer_id');
            $table->char('member_number_hash', 64);
            $table->text('member_number');
            $table->text('policy_number')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'insurer_id'])->references(['tenant_id', 'id'])->on('insurers')->restrictOnDelete();
            $table->unique(['tenant_id', 'insurer_id', 'member_number_hash']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_coverages');
    }
};
