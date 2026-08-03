<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bound configured appointment durations so generated slots cannot create unbounded work.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE appointment_types ADD CONSTRAINT appointment_types_duration_reasonable CHECK (duration_minutes BETWEEN 5 AND 720)',
            );
        }
    }

    /**
     * Remove the duration bound.
     */
    public function down(): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('ALTER TABLE appointment_types DROP CONSTRAINT IF EXISTS appointment_types_duration_reasonable');
        }
    }
};
