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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('invoice_id');
            $table->string('service_code', 80);
            $table->text('description');
            $table->decimal('quantity', 10, 3)->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('invoices')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'invoice_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
