<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The partial index enforces that a fulfilled appointment can only be claimed by one
     * active offer (offered or booked), preventing double assignment under concurrency.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX waitlist_entries_fulfilled_claim_unique '
            .'ON waitlist_entries (fulfilled_appointment_id) '
            ."WHERE fulfilled_appointment_id IS NOT NULL AND status IN ('offered', 'booked')",
        );
    }

    /**
     * Drop the single-claim guard when rolling back a deployment.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS waitlist_entries_fulfilled_claim_unique');
    }
};
