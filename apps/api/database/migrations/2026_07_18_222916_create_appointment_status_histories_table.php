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
        Schema::create('appointment_status_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('appointment_id');
            $table->enum('from_status', ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'])->nullable();
            $table->enum('to_status', ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show']);
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->text('context')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('appointments')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'appointment_id', 'occurred_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_appointment_status_history_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'appointment status history is append-only' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER appointment_status_history_immutable BEFORE UPDATE OR DELETE ON appointment_status_histories FOR EACH ROW EXECUTE FUNCTION reject_appointment_status_history_mutation()');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS appointment_status_history_immutable ON appointment_status_histories');
            DB::statement('DROP FUNCTION IF EXISTS reject_appointment_status_history_mutation');
        }

        Schema::dropIfExists('appointment_status_histories');
    }
};
