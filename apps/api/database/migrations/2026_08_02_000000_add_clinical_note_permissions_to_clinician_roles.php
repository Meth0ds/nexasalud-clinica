<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Grant the dedicated clinical-note capability to existing system clinician roles.
     */
    public function up(): void
    {
        Role::query()
            ->withoutGlobalScope('tenant')
            ->where('code', 'clinician')
            ->cursor()
            ->each(function (Role $role): void {
                $permissions = array_values(array_unique([
                    ...($role->permissions ?? []),
                    'clinical_notes.*',
                ]));

                $role->forceFill(['permissions' => $permissions])->save();
            });
    }

    /**
     * Remove only the capability introduced by this migration.
     */
    public function down(): void
    {
        Role::query()
            ->withoutGlobalScope('tenant')
            ->where('code', 'clinician')
            ->cursor()
            ->each(function (Role $role): void {
                $permissions = array_values(array_filter(
                    $role->permissions ?? [],
                    static fn (mixed $permission): bool => $permission !== 'clinical_notes.*',
                ));

                $role->forceFill(['permissions' => $permissions])->save();
            });
    }
};
