<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserPreference;
use App\Support\Authorization\PermissionService;

/**
 * Authorize users to manage only their own current-tenant interface preferences.
 */
class UserPreferencePolicy
{
    /**
     * Create the policy with the tenant-aware permission service.
     */
    public function __construct(private PermissionService $permissions) {}

    /**
     * Determine whether a membership context allows the user to list preferences.
     */
    public function viewAny(User $user): bool
    {
        return app()->bound('currentMembership');
    }

    /**
     * Determine whether the preference belongs to the authenticated user and current tenant.
     */
    public function view(User $user, UserPreference $preference): bool
    {
        return $preference->user_id === $user->getKey() && $this->permissions->belongsToCurrentTenant($preference);
    }

    /**
     * Determine whether a membership context allows preference creation.
     */
    public function create(User $user): bool
    {
        return app()->bound('currentMembership');
    }

    /**
     * Determine whether the user may update the given owned preference.
     */
    public function update(User $user, UserPreference $preference): bool
    {
        return $this->view($user, $preference);
    }

    /**
     * Determine whether the user may delete the given owned preference.
     */
    public function delete(User $user, UserPreference $preference): bool
    {
        return $this->view($user, $preference);
    }

    /**
     * Disallow preference restoration through policy authorization.
     */
    public function restore(User $user, UserPreference $preference): bool
    {
        return false;
    }

    /**
     * Disallow permanent preference deletion through policy authorization.
     */
    public function forceDelete(User $user, UserPreference $preference): bool
    {
        return false;
    }
}
