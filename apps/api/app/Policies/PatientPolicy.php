<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;
use App\Support\Authorization\PermissionService;

class PatientPolicy
{
    public function __construct(private PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'patients.read');
    }

    public function view(User $user, Patient $patient): bool
    {
        return $this->permissions->belongsToCurrentTenant($patient) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allows($user, 'patients.write');
    }

    public function update(User $user, Patient $patient): bool
    {
        return $this->permissions->belongsToCurrentTenant($patient) && $this->permissions->allows($user, 'patients.write');
    }

    public function delete(User $user, Patient $patient): bool
    {
        return false;
    }

    public function restore(User $user, Patient $patient): bool
    {
        return false;
    }

    public function forceDelete(User $user, Patient $patient): bool
    {
        return false;
    }
}
