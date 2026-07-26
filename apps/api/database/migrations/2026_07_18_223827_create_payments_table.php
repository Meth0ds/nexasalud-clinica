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
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('invoice_id');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('EUR');
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');
            $table->string('method_code', 40);
            $table->string('external_reference', 160)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'external_reference']);
            $table->index(['tenant_id', 'invoice_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
