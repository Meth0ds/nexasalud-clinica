<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the queued communication audit handoff idempotent at database level.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX audit_events_dispatch_unique ON audit_events (tenant_id, auditable_type, auditable_id) WHERE event_type = 'communication.dispatch_registered'",
            );
        }
    }

    /**
     * Remove the dispatch uniqueness guard.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS audit_events_dispatch_unique');
        }
    }
};
