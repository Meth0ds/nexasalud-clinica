<?php

namespace App\Policies;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Authorization\PermissionService;

/**
 * Authorize read-only access to tenant-scoped append-only audit events.
 */
class AuditEventPolicy
{
    /**
     * Create the policy with the tenant-aware permission service.
     */
    public function __construct(private PermissionService $permissions) {}

    /**
     * Determine whether the user may list audit events.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'audit.read');
    }

    /**
     * Determine whether the user may view a current-tenant audit event.
     */
    public function view(User $user, AuditEvent $event): bool
    {
        return $this->permissions->belongsToCurrentTenant($event) && $this->viewAny($user);
    }

    /**
     * Disallow application-level creation of audit events.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Disallow mutation of append-only audit events.
     */
    public function update(User $user, AuditEvent $event): bool
    {
        return false;
    }

    /**
     * Disallow deletion of append-only audit events.
     */
    public function delete(User $user, AuditEvent $event): bool
    {
        return false;
    }

    /**
     * Disallow restoration of audit events.
     */
    public function restore(User $user, AuditEvent $event): bool
    {
        return false;
    }

    /**
     * Disallow permanent deletion of audit events.
     */
    public function forceDelete(User $user, AuditEvent $event): bool
    {
        return false;
    }
}
