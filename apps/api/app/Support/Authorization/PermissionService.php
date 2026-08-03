<?php

namespace App\Support\Authorization;

use App\Models\Membership;
use App\Models\Clinic;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates tenant-scoped permissions and model ownership for the active request context.
 */
final class PermissionService
{
    /**
     * Determine whether the current active membership grants a requested permission or namespace wildcard.
     */
    public function allows(User $user, string $permission): bool
    {
        $membership = app()->bound('currentMembership') ? app('currentMembership') : null;

        if (! $membership instanceof Membership || $membership->user_id !== $user->getKey() || ! $membership->is_active) {
            return false;
        }

        return $membership->roles()
            ->get()
            ->contains(function ($role) use ($permission): bool {
                foreach ($role->permissions ?? [] as $granted) {
                    if (! is_string($granted)) {
                        continue;
                    }

                    if ($granted === '*' || $granted === $permission) {
                        return true;
                    }

                    if (str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1))) {
                        return true;
                    }
                }

                return false;
            });
    }

    /**
     * Determine whether a model belongs to the tenant bound to the current request.
     */
    public function belongsToCurrentTenant(Model $model): bool
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        return $tenant instanceof Tenant
            && $model->getAttribute('tenant_id') === $tenant->getKey();
    }

    /**
     * Determine whether a tenant-owned model is reachable from the active clinic.
     *
     * Models with a direct clinic_id are checked without an additional query. Patient and
     * clinical-note records use the normalized clinic_patient relationship instead.
     */
    public function belongsToCurrentClinic(Model $model): bool
    {
        $clinic = app()->bound('currentClinic') ? app('currentClinic') : null;

        if (! $clinic instanceof Clinic || ! $this->belongsToCurrentTenant($model)) {
            return false;
        }

        $directClinicId = $model->getAttribute('clinic_id');

        if (is_string($directClinicId) && $directClinicId !== '') {
            return $directClinicId === $clinic->getKey();
        }

        $patientId = $model instanceof \App\Models\Patient
            ? $model->getKey()
            : $model->getAttribute('patient_id');

        if (! is_string($patientId) || $patientId === '') {
            return false;
        }

        return DB::table('clinic_patient')
            ->where('tenant_id', $clinic->tenant_id)
            ->where('clinic_id', $clinic->getKey())
            ->where('patient_id', $patientId)
            ->where('is_active', true)
            ->exists();
    }
}
