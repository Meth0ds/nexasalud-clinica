<?php

namespace App\Policies;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Authorization\PermissionService;

class AuditEventPolicy
{
    public function __construct(private PermissionService $permissions) {}
    public function viewAny(User $user): bool { return $this->permissions->allows($user, 'audit.read'); }
    public function view(User $user, AuditEvent $event): bool { return $this->permissions->belongsToCurrentTenant($event) && $this->viewAny($user); }
    public function create(User $user): bool { return false; }
    public function update(User $user, AuditEvent $event): bool { return false; }
    public function delete(User $user, AuditEvent $event): bool { return false; }
    public function restore(User $user, AuditEvent $event): bool { return false; }
    public function forceDelete(User $user, AuditEvent $event): bool { return false; }
}
