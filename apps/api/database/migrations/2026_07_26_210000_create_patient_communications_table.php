<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_communications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('patient_id');
            $table->foreignUlid('author_user_id')->constrained('users')->restrictOnDelete();
            $table->ulid('consent_record_id')->nullable();
            $table->string('channel', 16)->index();
            $table->string('content_class', 24);
            $table->string('template_key', 80)->nullable();
            $table->text('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->timestampTz('scheduled_at')->nullable()->index();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('compliance_snapshot')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'consent_record_id'])->references(['tenant_id', 'id'])->on('consent_records')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'patient_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_communications');
    }
};
