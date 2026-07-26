<?php

namespace App\Policies;

use App\Models\Prescription;
use App\Models\User;
use App\Support\Authorization\PermissionService;

class PrescriptionPolicy
{
    public function __construct(private PermissionService $permissions) {}
    public function viewAny(User $user): bool { return $this->permissions->allows($user, 'prescriptions.read'); }
    public function view(User $user, Prescription $prescription): bool { return $this->permissions->belongsToCurrentTenant($prescription) && $this->viewAny($user); }
    public function create(User $user): bool { return $this->permissions->allows($user, 'prescriptions.write'); }
    public function update(User $user, Prescription $prescription): bool { return $this->permissions->belongsToCurrentTenant($prescription) && $this->permissions->allows($user, 'prescriptions.write'); }
    public function sign(User $user, Prescription $prescription): bool { return $this->permissions->belongsToCurrentTenant($prescription) && $this->permissions->allows($user, 'prescriptions.sign'); }
    public function send(User $user, Prescription $prescription): bool { return $this->permissions->belongsToCurrentTenant($prescription) && $this->permissions->allows($user, 'prescriptions.send'); }
    public function delete(User $user, Prescription $prescription): bool { return false; }
    public function restore(User $user, Prescription $prescription): bool { return false; }
    public function forceDelete(User $user, Prescription $prescription): bool { return false; }
}
