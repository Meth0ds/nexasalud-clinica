<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('author_user_id')->constrained('users')->restrictOnDelete();
            $table->ulid('supersedes_id')->nullable()->index();
            $table->string('status', 24)->index();
            $table->text('content');
            $table->text('signature_context')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id', 'created_at']);
            $table->index(['tenant_id', 'appointment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_notes');
    }
};
