<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserPreference;
use App\Support\Authorization\PermissionService;

class UserPreferencePolicy
{
    public function __construct(private PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return app()->bound('currentMembership');
    }

    public function view(User $user, UserPreference $preference): bool
    {
        return $preference->user_id === $user->getKey() && $this->permissions->belongsToCurrentTenant($preference);
    }

    public function create(User $user): bool
    {
        return app()->bound('currentMembership');
    }

    public function update(User $user, UserPreference $preference): bool
    {
        return $this->view($user, $preference);
    }

    public function delete(User $user, UserPreference $preference): bool
    {
        return $this->view($user, $preference);
    }

    public function restore(User $user, UserPreference $preference): bool
    {
        return false;
    }

    public function forceDelete(User $user, UserPreference $preference): bool
    {
        return false;
    }
}
