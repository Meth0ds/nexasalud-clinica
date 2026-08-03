<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tables in the clinical data plane that must never be readable without an
     * explicit database tenant context.
     *
     * Authentication and context-discovery tables are intentionally excluded:
     * ResolveTenantContext must be able to discover a user's membership before
     * the request has a tenant setting. Every clinical request sets the setting
     * before touching these tables.
     *
     * @var list<string>
     */
    private const PROTECTED_TABLES = [
        'patients',
        'appointment_types',
        'rooms',
        'appointments',
        'appointment_status_histories',
        'appointment_change_histories',
        'appointment_slot_holds',
        'waitlist_entries',
        'prescriptions',
        'prescription_items',
        'prescription_status_histories',
        'patient_allergies',
        'adverse_reactions',
        'patient_coverages',
        'insurers',
        'consent_records',
        'invoices',
        'invoice_items',
        'payments',
        'clinical_documents',
        'clinical_notes',
        'patient_communications',
        'patient_user_links',
        'portal_demo_requests',
        'user_preferences',
        'audit_events',
        'idempotency_keys',
        'clinic_patient',
    ];

    /**
     * Enable fail-closed tenant RLS for the clinical data plane on PostgreSQL.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::PROTECTED_TABLES as $table) {
            $tableExists = DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists();

            $tenantColumnExists = DB::table('information_schema.columns')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->where('column_name', 'tenant_id')
                ->exists();

            if (! $tableExists || ! $tenantColumnExists) {
                continue;
            }

            $quotedTable = '"'.str_replace('"', '""', $table).'"';
            $policyName = 'tenant_isolation_policy';
            $quotedPolicy = '"'.str_replace('"', '""', $policyName).'"';

            DB::statement("ALTER TABLE {$quotedTable} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$quotedTable} FORCE ROW LEVEL SECURITY");
            $policyExists = DB::table('pg_policies')
                ->where('schemaname', 'public')
                ->where('tablename', $table)
                ->where('policyname', $policyName)
                ->exists();

            if (! $policyExists) {
                DB::statement(
                    "CREATE POLICY {$quotedPolicy} ON {$quotedTable} "
                   ."USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::text) "
                    ."WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::text)",
                );
            }
        }
    }

    /**
     * Remove the application policy when rolling back a deployment.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::PROTECTED_TABLES as $table) {
            $tableExists = DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->exists();

            if (! $tableExists) {
                continue;
            }

            $quotedTable = '"'.str_replace('"', '""', $table).'"';
            DB::statement("DROP POLICY IF EXISTS \"tenant_isolation_policy\" ON {$quotedTable}");
            DB::statement("ALTER TABLE {$quotedTable} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$quotedTable} DISABLE ROW LEVEL SECURITY");
        }
    }
};
