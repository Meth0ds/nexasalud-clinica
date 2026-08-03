<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep generated invoice documents tied to their source invoice.
     */
    public function up(): void
    {
        Schema::table('clinical_documents', function (Blueprint $table): void {
            $table->foreignUlid('invoice_id')->nullable()->after('prescription_id');
            $table->index(['tenant_id', 'invoice_id']);
            $table->foreign(['tenant_id', 'invoice_id'])
                ->references(['tenant_id', 'id'])
                ->on('invoices')
                ->restrictOnDelete();
        });
    }

    /**
     * Remove the invoice association.
     */
    public function down(): void
    {
        Schema::table('clinical_documents', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'invoice_id']);
            $table->dropIndex(['tenant_id', 'invoice_id']);
            $table->dropColumn('invoice_id');
        });
    }
};
