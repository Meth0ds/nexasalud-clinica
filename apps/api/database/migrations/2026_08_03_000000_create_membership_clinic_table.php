<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the explicit staff-to-clinic boundary used by every clinical request.
     */
    public function up(): void
    {
        Schema::create('membership_clinic', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id');
            $table->foreignUlid('membership_id');
            $table->foreignUlid('clinic_id');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'membership_id', 'clinic_id']);
            $table->index(['membership_id', 'is_active']);
            $table->index(['clinic_id', 'is_active']);
            $table->foreign(['tenant_id', 'membership_id'])
                ->references(['tenant_id', 'id'])
                ->on('memberships')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'clinic_id'])
                ->references(['tenant_id', 'id'])
                ->on('clinics')
                ->cascadeOnDelete();
        });

        $timestamp = now();
        $rows = DB::table('memberships')
            ->join('clinics', 'clinics.tenant_id', '=', 'memberships.tenant_id')
            ->where('memberships.is_active', true)
            ->where('clinics.is_active', true)
            ->whereNull('clinics.deleted_at')
            ->select([
                'memberships.tenant_id',
                'memberships.id as membership_id',
                'clinics.id as clinic_id',
            ])
            ->get();

        foreach ($rows as $row) {
            DB::table('membership_clinic')->insertOrIgnore([
                'id' => (string) str()->ulid(),
                'tenant_id' => $row->tenant_id,
                'membership_id' => $row->membership_id,
                'clinic_id' => $row->clinic_id,
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    /**
     * Remove the explicit clinic boundary.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_clinic');
    }
};
