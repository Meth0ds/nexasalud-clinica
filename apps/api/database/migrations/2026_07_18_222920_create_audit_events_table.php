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
        Schema::create('audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_subject', 255)->nullable();
            $table->string('event_type', 120);
            $table->string('auditable_type', 160)->nullable();
            $table->string('auditable_id', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->text('metadata')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'event_type', 'occurred_at']);
            $table->index(['tenant_id', 'auditable_type', 'auditable_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_audit_event_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit events are append-only' USING ERRCODE = '55000';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER audit_events_immutable BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation()');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS audit_events_immutable ON audit_events');
            DB::statement('DROP FUNCTION IF EXISTS reject_audit_event_mutation');
        }

        Schema::dropIfExists('audit_events');
    }
};
