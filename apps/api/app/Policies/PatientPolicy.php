<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;
use App\Support\Authorization\PermissionService;

/**
 * Authorize tenant-scoped patient records while forbidding destructive deletion.
 */
class PatientPolicy
{
    /**
     * Create the policy with the tenant-aware permission service.
     */
    public function __construct(private PermissionService $permissions) {}

    /**
     * Determine whether the user may list patients.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'patients.read');
    }

    /**
     * Determine whether the user may view a current-tenant patient.
     */
    public function view(User $user, Patient $patient): bool
    {
        return $this->permissions->belongsToCurrentTenant($patient) && $this->viewAny($user);
    }

    /**
     * Determine whether the user may create patients.
     */
    public function create(User $user): bool
    {
        return $this->permissions->allows($user, 'patients.write');
    }

    /**
     * Determine whether the user may update a current-tenant patient.
     */
    public function update(User $user, Patient $patient): bool
    {
        return $this->permissions->belongsToCurrentTenant($patient) && $this->permissions->allows($user, 'patients.write');
    }

    /**
     * Disallow patient deletion through policy authorization.
     */
    public function delete(User $user, Patient $patient): bool
    {
        return false;
    }

    /**
     * Disallow patient restoration through policy authorization.
     */
    public function restore(User $user, Patient $patient): bool
    {
        return false;
    }

    /**
     * Disallow permanent patient deletion through policy authorization.
     */
    public function forceDelete(User $user, Patient $patient): bool
    {
        return false;
    }
}
