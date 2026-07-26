<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\User;
use App\Support\Authorization\PermissionService;

final class ClinicalNotePolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function viewAny(User $user, Patient $patient): bool
    {
        return $this->permissions->belongsToCurrentTenant($patient)
            && $this->permissions->allows($user, 'patients.read');
    }

    public function view(User $user, ClinicalNote $note): bool
    {
        return $this->permissions->belongsToCurrentTenant($note)
            && $this->permissions->allows($user, 'patients.read');
    }

    public function create(User $user, Patient $patient): bool
    {
        return $this->permissions->belongsToCurrentTenant($patient)
            && $this->permissions->allows($user, 'patients.write');
    }

    public function sign(User $user, Patient $patient): bool
    {
        return $this->permissions->belongsToCurrentTenant($patient)
            && $this->permissions->allows($user, 'patients.sign');
    }

    public function update(User $user, ClinicalNote $note): bool
    {
        return false;
    }

    public function delete(User $user, ClinicalNote $note): bool
    {
        return false;
    }
}
