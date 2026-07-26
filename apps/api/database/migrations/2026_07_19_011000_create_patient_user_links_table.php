<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_user_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('patient_id');
            $table->enum('relationship', ['self', 'representative', 'guardian'])->default('self');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('verified_at');
            $table->timestampTz('access_expires_at')->nullable();
            $table->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'user_id', 'patient_id', 'relationship']);
            $table->index(['tenant_id', 'user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_user_links');
    }
};
