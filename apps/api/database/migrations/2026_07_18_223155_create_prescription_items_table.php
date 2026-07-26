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
        Schema::create('prescription_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('prescription_id');
            $table->string('medication_code_system', 80);
            $table->string('medication_code', 100);
            $table->text('medication_display')->nullable();
            $table->decimal('dose_amount', 10, 3)->nullable();
            $table->string('dose_unit_code', 40)->nullable();
            $table->string('route_code', 60)->nullable();
            $table->string('frequency_code', 60)->nullable();
            $table->unsignedSmallInteger('duration_value')->nullable();
            $table->enum('duration_unit', ['day', 'week', 'month'])->nullable();
            $table->decimal('quantity', 10, 3)->nullable();
            $table->string('quantity_unit_code', 40)->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'prescription_id'])->references(['tenant_id', 'id'])->on('prescriptions')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'prescription_id', 'sort_order']);
            $table->index(['tenant_id', 'medication_code_system', 'medication_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
    }
};
