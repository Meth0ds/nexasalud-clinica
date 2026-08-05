<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add offer visibility and offer-owned appointment tracking to waitlist entries.
     */
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->timestampTz('offer_seen_at')->nullable()->after('offer_expires_at');
            $table->boolean('offer_created_appointment')->default(false)->after('offer_seen_at');
        });
    }

    /**
     * Remove the offer lifecycle columns when rolling back a deployment.
     */
    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->dropColumn(['offer_created_appointment', 'offer_seen_at']);
        });
    }
};
