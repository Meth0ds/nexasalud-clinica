<?php

declare(strict_types=1);

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
        Schema::create('portal_demo_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('patient_id');
            $table->foreignUlid('prescription_id')->constrained()->restrictOnDelete();
            $table->string('request_type', 64);
            $table->string('status', 32);
            $table->timestampTz('submitted_at');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->unique(['tenant_id', 'patient_id', 'prescription_id', 'request_type'], 'portal_demo_request_once_per_prescription');
            $table->index(['tenant_id', 'patient_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_demo_requests');
    }
};
