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
        Schema::create('consent_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('patient_id');
            $table->string('consent_type', 100);
            $table->string('document_version', 60);
            $table->enum('status', ['active', 'withdrawn', 'expired'])->default('active');
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('granted_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id', 'consent_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
