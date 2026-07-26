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
        Schema::create('rooms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('clinic_id');
            $table->string('code', 40);
            $table->string('name', 120);
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->boolean('supports_video')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['tenant_id', 'clinic_id'])->references(['tenant_id', 'id'])->on('clinics')->cascadeOnDelete();
            $table->unique(['tenant_id', 'clinic_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
