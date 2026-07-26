<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clinical_notes ALTER COLUMN signature_context TYPE TEXT USING signature_context::text');

            return;
        }

        Schema::table('clinical_notes', function (Blueprint $table): void {
            $table->text('signature_context')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clinical_notes ALTER COLUMN signature_context TYPE JSON USING signature_context::json');

            return;
        }

        Schema::table('clinical_notes', function (Blueprint $table): void {
            $table->json('signature_context')->nullable()->change();
        });
    }
};
