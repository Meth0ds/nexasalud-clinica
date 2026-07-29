<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use App\Support\Authorization\PermissionService;

/**
 * Authorize appointment operations within the current tenant and permission context.
 */
class AppointmentPolicy
{
    /**
     * Create the policy with the tenant-aware permission service.
     */
    public function __construct(private PermissionService $permissions) {}

    /**
     * Determine whether the user may list appointments.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'appointments.read');
    }

    /**
     * Determine whether the user may view a current-tenant appointment.
     */
    public function view(User $user, Appointment $appointment): bool
    {
        return $this->sameTenant($appointment) && $this->viewAny($user);
    }

    /**
     * Determine whether the user may create appointments.
     */
    public function create(User $user): bool
    {
        return $this->permissions->allows($user, 'appointments.write');
    }

    /**
     * Determine whether the user may update a current-tenant appointment.
     */
    public function update(User $user, Appointment $appointment): bool
    {
        return $this->sameTenant($appointment) && $this->permissions->allows($user, 'appointments.write');
    }

    /**
     * Determine whether the user may cancel a current-tenant appointment.
     */
    public function delete(User $user, Appointment $appointment): bool
    {
        return $this->sameTenant($appointment) && $this->permissions->allows($user, 'appointments.cancel');
    }

    /**
     * Disallow restoration of appointments through policy authorization.
     */
    public function restore(User $user, Appointment $appointment): bool
    {
        return false;
    }

    /**
     * Disallow permanent appointment deletion through policy authorization.
     */
    public function forceDelete(User $user, Appointment $appointment): bool
    {
        return false;
    }

    /**
     * Determine whether an appointment belongs to the active tenant context.
     */
    private function sameTenant(Appointment $appointment): bool
    {
        return $this->permissions->belongsToCurrentTenant($appointment);
    }
}
