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
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('actor_subject', 255);
            $table->string('operation', 120);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->string('resource_type', 160)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'actor_subject', 'operation', 'key_hash']);
            $table->unique(['tenant_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
