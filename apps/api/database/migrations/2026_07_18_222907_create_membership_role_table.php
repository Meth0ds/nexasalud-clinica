<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('membership_role', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->ulid('membership_id');
            $table->ulid('role_id');
            $table->foreignUlid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('granted_at')->useCurrent();

            $table->primary(['membership_id', 'role_id']);
            $table->foreign(['tenant_id', 'membership_id'])->references(['tenant_id', 'id'])->on('memberships')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
            $table->index(['tenant_id', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_role');
    }
};
