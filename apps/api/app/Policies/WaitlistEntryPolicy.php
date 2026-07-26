<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Authorization\PermissionService;

class WaitlistEntryPolicy
{
    public function __construct(private PermissionService $permissions) {}
    public function viewAny(User $user): bool { return $this->permissions->allows($user, 'appointments.read'); }
    public function view(User $user, WaitlistEntry $entry): bool { return $this->permissions->belongsToCurrentTenant($entry) && $this->viewAny($user); }
    public function create(User $user): bool { return $this->permissions->allows($user, 'appointments.write'); }
    public function update(User $user, WaitlistEntry $entry): bool { return $this->permissions->belongsToCurrentTenant($entry) && $this->permissions->allows($user, 'appointments.write'); }
    public function delete(User $user, WaitlistEntry $entry): bool { return false; }
    public function restore(User $user, WaitlistEntry $entry): bool { return false; }
    public function forceDelete(User $user, WaitlistEntry $entry): bool { return false; }
}
