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
        Schema::create('insurers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 160);
            $table->string('provider_reference', 160)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurers');
    }
};
