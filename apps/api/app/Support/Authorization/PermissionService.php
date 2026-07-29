<?php

namespace App\Support\Authorization;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Evaluates tenant-scoped permissions and model ownership for the active request context.
 */
final class PermissionService
{
    /**
     * Determine whether the current active membership grants a requested permission or namespace wildcard.
     */
    public function allows(User $user, string $permission): bool
    {
        $membership = app()->bound('currentMembership') ? app('currentMembership') : null;

        if (! $membership instanceof Membership || $membership->user_id !== $user->getKey() || ! $membership->is_active) {
            return false;
        }

        return $membership->roles()
            ->get()
            ->contains(function ($role) use ($permission): bool {
                foreach ($role->permissions ?? [] as $granted) {
                    if (! is_string($granted)) {
                        continue;
                    }

                    if ($granted === '*' || $granted === $permission) {
                        return true;
                    }

                    if (str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1))) {
                        return true;
                    }
                }

                return false;
            });
    }

    /**
     * Determine whether a model belongs to the tenant bound to the current request.
     */
    public function belongsToCurrentTenant(Model $model): bool
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        return $tenant instanceof Tenant
            && $model->getAttribute('tenant_id') === $tenant->getKey();
    }
}
