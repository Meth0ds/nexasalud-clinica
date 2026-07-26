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
        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('clinic_id');
            $table->ulid('patient_id');
            $table->ulid('patient_coverage_id')->nullable();
            $table->ulid('appointment_id')->nullable();
            $table->string('invoice_number', 80);
            $table->enum('status', ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'voided'])->default('draft');
            $table->char('currency', 3)->default('EUR');
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'clinic_id'])->references(['tenant_id', 'id'])->on('clinics')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'patient_coverage_id'])->references(['tenant_id', 'id'])->on('patient_coverages')->restrictOnDelete();
            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('appointments')->restrictOnDelete();
            $table->unique(['tenant_id', 'invoice_number']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'due_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
