<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CommunicationContentClass;
use App\Models\PatientCommunication;
use App\Models\User;
use App\Support\Authorization\PermissionService;

/**
 * Authorize tenant-scoped patient communications while protecting clinical-link content.
 */
final class PatientCommunicationPolicy
{
    /**
     * Create the policy with the tenant-aware permission service.
     */
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * Determine whether the user may list patient communications.
     */
    public function viewAny(User $user): bool
    {
        return $this->permissions->allows($user, 'communications.read');
    }

    /**
     * Determine whether the user may view a current-tenant communication and its content class.
     */
    public function view(User $user, PatientCommunication $communication): bool
    {
        return $this->permissions->belongsToCurrentClinic($communication)
            && $this->viewAny($user)
            && $this->canAccessClinicalContent($user, $communication);
    }

    /**
     * Determine whether the user may create patient communications.
     */
    public function create(User $user): bool
    {
        return $this->permissions->allows($user, 'communications.write');
    }

    /**
     * Determine whether the user may update a current-tenant communication and its content class.
     */
    public function update(User $user, PatientCommunication $communication): bool
    {
        return $this->permissions->belongsToCurrentClinic($communication)
            && $this->permissions->allows($user, 'communications.write')
            && $this->canAccessClinicalContent($user, $communication, 'communications.clinical.write');
    }

    /**
     * Disallow deletion of patient communications through policy authorization.
     */
    public function delete(User $user, PatientCommunication $communication): bool
    {
        return false;
    }

    /**
     * Determine whether a communication's clinical-link content requires and has the given permission.
     */
    private function canAccessClinicalContent(
        User $user,
        PatientCommunication $communication,
        string $clinicalPermission = 'communications.clinical.read',
    ): bool {
        return $communication->content_class !== CommunicationContentClass::ClinicalLink
            || $this->permissions->allows($user, $clinicalPermission);
    }
}
