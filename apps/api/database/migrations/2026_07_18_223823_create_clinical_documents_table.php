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
        Schema::create('clinical_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('patient_id');
            $table->ulid('appointment_id')->nullable();
            $table->ulid('prescription_id')->nullable();
            $table->ulid('consent_record_id')->nullable();
            $table->string('document_type', 100);
            $table->enum('status', ['generating', 'ready', 'failed', 'voided', 'expired'])->default('generating');
            $table->char('sha256', 64)->nullable();
            $table->string('template_version', 60);
            $table->string('storage_key', 500);
            $table->json('metadata')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignUlid('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'prescription_id'])->references(['tenant_id', 'id'])->on('prescriptions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'consent_record_id'])->references(['tenant_id', 'id'])->on('consent_records')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'storage_key']);
            $table->index(['tenant_id', 'patient_id', 'document_type', 'created_at']);
            $table->index(['tenant_id', 'appointment_id']);
            $table->index(['tenant_id', 'prescription_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinical_documents');
    }
};
