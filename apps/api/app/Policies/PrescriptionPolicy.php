<?php

namespace App\Policies;

use App\Models\Prescription;
use App\Models\User;
use App\Support\Authorization\PermissionService;

/**
 * Authorize tenant-scoped prescription workflows, including signing and dispatch.
 */
class PrescriptionPolicy
{
    /**
     * Create the policy with the tenant-aware permission service.
     */
    public function __construct(private PermissionService $permissions) {}

    /**
     * Determine whether the user may list prescriptions.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'prescriptions.read');
    }

    /**
     * Determine whether the user may view a current-tenant prescription.
     */
    public function view(User $user, Prescription $prescription): bool
    {
        return $this->permissions->belongsToCurrentClinic($prescription) && $this->viewAny($user);
    }

    /**
     * Determine whether the user may create prescriptions.
     */
    public function create(User $user): bool
    {
        return $this->permissions->allows($user, 'prescriptions.write');
    }

    /**
     * Determine whether the user may update a current-tenant prescription.
     */
    public function update(User $user, Prescription $prescription): bool
    {
        return $this->permissions->belongsToCurrentClinic($prescription) && $this->permissions->allows($user, 'prescriptions.write');
    }

    /**
     * Determine whether the user may sign a current-tenant prescription.
     */
    public function sign(User $user, Prescription $prescription): bool
    {
        return $this->permissions->belongsToCurrentClinic($prescription) && $this->permissions->allows($user, 'prescriptions.sign');
    }

    /**
     * Determine whether the user may send a current-tenant prescription externally.
     */
    public function send(User $user, Prescription $prescription): bool
    {
        return $this->permissions->belongsToCurrentClinic($prescription) && $this->permissions->allows($user, 'prescriptions.send');
    }

    /**
     * Disallow prescription deletion through policy authorization.
     */
    public function delete(User $user, Prescription $prescription): bool
    {
        return false;
    }

    /**
     * Disallow prescription restoration through policy authorization.
     */
    public function restore(User $user, Prescription $prescription): bool
    {
        return false;
    }

    /**
     * Disallow permanent prescription deletion through policy authorization.
     */
    public function forceDelete(User $user, Prescription $prescription): bool
    {
        return false;
    }
}
