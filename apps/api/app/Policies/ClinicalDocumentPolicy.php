<?php

namespace App\Policies;

use App\Models\ClinicalDocument;
use App\Models\PatientUserLink;
use App\Models\User;
use App\Support\Authorization\PermissionService;

/**
 * Authorize clinical document access for clinical staff and linked portal patients.
 */
class ClinicalDocumentPolicy
{
    /**
     * Create the policy with the tenant-aware permission service.
     */
    public function __construct(private PermissionService $permissions) {}

    /**
     * Determine whether the user has any permitted clinical document access path.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'documents.read') || $this->permissions->allows($user, 'patients.read') || $this->permissions->allows($user, 'portal.self');
    }

    /**
     * Determine whether the user may view the current-tenant document through staff or portal access.
     */
    public function view(User $user, ClinicalDocument $document): bool
    {
        if (! $this->permissions->belongsToCurrentClinic($document)) {
            return false;
        }

        if ($this->permissions->allows($user, 'documents.read')) {
            return true;
        }

        return $this->permissions->allows($user, 'portal.self')
            && PatientUserLink::query()
                ->where('user_id', $user->getKey())
                ->where('patient_id', $document->patient_id)
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now()))
                ->exists();
    }

    /**
     * Determine whether the user may request clinical document generation.
     */
    public function create(User $user): bool
    {
        return $this->permissions->allows($user, 'documents.generate') || $this->permissions->allows($user, 'patients.write');
    }

    /**
     * Disallow updates to generated clinical documents.
     */
    public function update(User $user, ClinicalDocument $document): bool
    {
        return false;
    }

    /**
     * Disallow deletion of clinical documents through policy authorization.
     */
    public function delete(User $user, ClinicalDocument $document): bool
    {
        return false;
    }

    /**
     * Disallow restoration of clinical documents through policy authorization.
     */
    public function restore(User $user, ClinicalDocument $document): bool
    {
        return false;
    }

    /**
     * Disallow permanent clinical document deletion through policy authorization.
     */
    public function forceDelete(User $user, ClinicalDocument $document): bool
    {
        return false;
    }
}
