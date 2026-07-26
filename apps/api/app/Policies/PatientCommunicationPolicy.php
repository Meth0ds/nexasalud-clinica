<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CommunicationContentClass;
use App\Models\PatientCommunication;
use App\Models\User;
use App\Support\Authorization\PermissionService;

final class PatientCommunicationPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'communications.read');
    }

    public function view(User $user, PatientCommunication $communication): bool
    {
        return $this->permissions->belongsToCurrentTenant($communication)
            && $this->viewAny($user)
            && $this->canAccessClinicalContent($user, $communication);
    }

    public function create(User $user): bool
    {
        return $this->permissions->allows($user, 'communications.write');
    }

    public function update(User $user, PatientCommunication $communication): bool
    {
        return $this->permissions->belongsToCurrentTenant($communication)
            && $this->permissions->allows($user, 'communications.write')
            && $this->canAccessClinicalContent($user, $communication, 'communications.clinical.write');
    }

    public function delete(User $user, PatientCommunication $communication): bool
    {
        return false;
    }

    private function canAccessClinicalContent(
        User $user,
        PatientCommunication $communication,
        string $clinicalPermission = 'communications.clinical.read',
    ): bool {
        return $communication->content_class !== CommunicationContentClass::ClinicalLink
            || $this->permissions->allows($user, $clinicalPermission);
    }
}
