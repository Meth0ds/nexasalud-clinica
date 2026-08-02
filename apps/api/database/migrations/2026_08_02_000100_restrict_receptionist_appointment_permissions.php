<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Replace the broad appointment namespace on receptionist roles with operational permissions.
     */
    public function up(): void
    {
        Role::query()
            ->withoutGlobalScope('tenant')
            ->where('code', 'receptionist')
            ->cursor()
            ->each(function (Role $role): void {
                $permissions = array_values(array_filter(
                    $role->permissions ?? [],
                    static fn (mixed $permission): bool => $permission !== 'appointments.*',
                ));

                foreach (['appointments.read', 'appointments.write', 'appointments.cancel'] as $permission) {
                    if (! in_array($permission, $permissions, true)) {
                        $permissions[] = $permission;
                    }
                }

                $role->forceFill(['permissions' => $permissions])->save();
            });
    }

    /**
     * Restore the original receptionist appointment namespace wildcard.
     */
    public function down(): void
    {
        Role::query()
            ->withoutGlobalScope('tenant')
            ->where('code', 'receptionist')
            ->cursor()
            ->each(function (Role $role): void {
                $permissions = array_values(array_filter(
                    $role->permissions ?? [],
                    static fn (mixed $permission): bool => ! in_array(
                        $permission,
                        ['appointments.read', 'appointments.write', 'appointments.cancel'],
                        true,
                    ),
                ));

                if (! in_array('appointments.*', $permissions, true)) {
                    $permissions[] = 'appointments.*';
                }

                $role->forceFill(['permissions' => $permissions])->save();
            });
    }
};
