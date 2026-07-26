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
        Schema::create('patients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('medical_record_hash', 64);
            $table->text('medical_record_number');
            $table->text('first_name');
            $table->text('last_name');
            $table->text('birth_date')->nullable();
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->text('address')->nullable();
            $table->text('clinical_notes')->nullable();
            $table->string('preferred_language', 10)->default('es');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'medical_record_hash']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'is_active', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
