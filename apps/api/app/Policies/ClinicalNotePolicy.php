<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\User;
use App\Support\Authorization\PermissionService;

/**
 * Authorize tenant-scoped clinical note reads, creation, and signing.
 */
final class ClinicalNotePolicy
{
    /**
     * Create the policy with the tenant-aware permission service.
     */
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * Determine whether the user may list notes for the current-tenant patient.
     */
    public function viewAny(User $user, Patient $patient): bool
    {
        return $this->permissions->belongsToCurrentTenant($patient)
            && $this->permissions->allows($user, 'clinical_notes.read');
    }

    /**
     * Determine whether the user may view a current-tenant clinical note.
     */
    public function view(User $user, ClinicalNote $note): bool
    {
        return $this->permissions->belongsToCurrentTenant($note)
            && $this->permissions->allows($user, 'clinical_notes.read');
    }

    /**
     * Determine whether the user may create a clinical note for the current-tenant patient.
     */
    public function create(User $user, Patient $patient): bool
    {
        return $this->permissions->belongsToCurrentTenant($patient)
            && $this->permissions->allows($user, 'clinical_notes.write');
    }

    /**
     * Determine whether the user may sign clinical notes for the current-tenant patient.
     */
    public function sign(User $user, Patient $patient): bool
    {
        return $this->permissions->belongsToCurrentTenant($patient)
            && $this->permissions->allows($user, 'clinical_notes.sign');
    }

    /**
     * Disallow updates to clinical notes through policy authorization.
     */
    public function update(User $user, ClinicalNote $note): bool
    {
        return false;
    }

    /**
     * Disallow deletion of clinical notes through policy authorization.
     */
    public function delete(User $user, ClinicalNote $note): bool
    {
        return false;
    }
}
