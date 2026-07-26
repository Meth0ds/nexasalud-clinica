<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prescription_status_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('prescription_id');
            $table->enum('from_status', ['draft', 'pending_review', 'signed', 'sent', 'cancelled'])->nullable();
            $table->enum('to_status', ['draft', 'pending_review', 'signed', 'sent', 'cancelled']);
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->text('context')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->foreign(['tenant_id', 'prescription_id'])->references(['tenant_id', 'id'])->on('prescriptions')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'prescription_id', 'occurred_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_prescription_status_history_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'prescription status history is append-only' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER prescription_status_history_immutable BEFORE UPDATE OR DELETE ON prescription_status_histories FOR EACH ROW EXECUTE FUNCTION reject_prescription_status_history_mutation()');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS prescription_status_history_immutable ON prescription_status_histories');
            DB::statement('DROP FUNCTION IF EXISTS reject_prescription_status_history_mutation');
        }

        Schema::dropIfExists('prescription_status_histories');
    }
};
