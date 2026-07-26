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
        Schema::create('appointment_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 140);
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->string('color', 9)->nullable();
            $table->boolean('requires_room')->default(true);
            $table->boolean('allows_video')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_types');
    }
};
