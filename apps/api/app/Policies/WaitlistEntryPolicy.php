<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Authorization\PermissionService;

/**
 * Authorize tenant-scoped waitlist operations through appointment permissions.
 */
class WaitlistEntryPolicy
{
    /**
     * Create the policy with the tenant-aware permission service.
     */
    public function __construct(private PermissionService $permissions) {}

    /**
     * Determine whether the user may list waitlist entries.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'appointments.read');
    }

    /**
     * Determine whether the user may view a current-tenant waitlist entry.
     */
    public function view(User $user, WaitlistEntry $entry): bool
    {
        return $this->permissions->belongsToCurrentTenant($entry) && $this->viewAny($user);
    }

    /**
     * Determine whether the user may create waitlist entries.
     */
    public function create(User $user): bool
    {
        return $this->permissions->allows($user, 'appointments.write');
    }

    /**
     * Determine whether the user may update a current-tenant waitlist entry.
     */
    public function update(User $user, WaitlistEntry $entry): bool
    {
        return $this->permissions->belongsToCurrentTenant($entry) && $this->permissions->allows($user, 'appointments.write');
    }

    /**
     * Disallow waitlist entry deletion through policy authorization.
     */
    public function delete(User $user, WaitlistEntry $entry): bool
    {
        return false;
    }

    /**
     * Disallow waitlist entry restoration through policy authorization.
     */
    public function restore(User $user, WaitlistEntry $entry): bool
    {
        return false;
    }

    /**
     * Disallow permanent waitlist entry deletion through policy authorization.
     */
    public function forceDelete(User $user, WaitlistEntry $entry): bool
    {
        return false;
    }
}
