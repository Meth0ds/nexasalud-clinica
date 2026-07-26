<?php

namespace App\Policies;

use App\Models\ClinicalDocument;
use App\Models\PatientUserLink;
use App\Models\User;
use App\Support\Authorization\PermissionService;

class ClinicalDocumentPolicy
{
    public function __construct(private PermissionService $permissions) {}
    public function viewAny(User $user): bool { return $this->permissions->allows($user, 'documents.read') || $this->permissions->allows($user, 'patients.read') || $this->permissions->allows($user, 'portal.self'); }
    public function view(User $user, ClinicalDocument $document): bool
    {
        if (! $this->permissions->belongsToCurrentTenant($document)) {
            return false;
        }

        if ($this->permissions->allows($user, 'documents.read') || $this->permissions->allows($user, 'patients.read')) {
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
    public function create(User $user): bool { return $this->permissions->allows($user, 'documents.generate') || $this->permissions->allows($user, 'patients.write'); }
    public function update(User $user, ClinicalDocument $document): bool { return false; }
    public function delete(User $user, ClinicalDocument $document): bool { return false; }
    public function restore(User $user, ClinicalDocument $document): bool { return false; }
    public function forceDelete(User $user, ClinicalDocument $document): bool { return false; }
}
