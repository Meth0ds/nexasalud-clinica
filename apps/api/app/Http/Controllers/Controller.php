<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provide the shared base type for application HTTP controllers.
 */
abstract class Controller
{
    /**
     * Resolve the request-scoped tenant or fail closed.
     */
    protected function currentTenant(): Tenant
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        abort_unless($tenant instanceof Tenant, Response::HTTP_FORBIDDEN);

        return $tenant;
    }

    /**
     * Resolve the request-scoped clinic or fail closed.
     */
    protected function currentClinic(): Clinic
    {
        $clinic = app()->bound('currentClinic') ? app('currentClinic') : null;
        abort_unless($clinic instanceof Clinic, Response::HTTP_FORBIDDEN);

        return $clinic;
    }

    /**
     * Require an input clinic to match the authorized request context.
     */
    protected function requireCurrentClinic(string $clinicId): Clinic
    {
        $clinic = $this->currentClinic();
        abort_unless(hash_equals($clinic->getKey(), $clinicId), Response::HTTP_FORBIDDEN);

        return $clinic;
    }

    /**
     * Require that the patient is explicitly linked to the active clinic.
     */
    protected function requirePatientInCurrentClinic(Patient $patient): void
    {
        $clinic = $this->currentClinic();
        abort_unless(
            $patient->tenant_id === $clinic->tenant_id
                && DB::table('clinic_patient')
                    ->where('tenant_id', $clinic->tenant_id)
                    ->where('clinic_id', $clinic->getKey())
                    ->where('patient_id', $patient->getKey())
                    ->where('is_active', true)
                    ->exists(),
            Response::HTTP_FORBIDDEN,
        );
    }

    /**
     * Require that a staff identity has an active membership grant for the active clinic.
     */
    protected function requirePractitionerInCurrentClinic(string $userId): void
    {
        $tenant = $this->currentTenant();
        $clinic = $this->currentClinic();
        $allowed = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('memberships.tenant_id', $tenant->getKey())
            ->where('memberships.user_id', $userId)
            ->where('memberships.is_active', true)
            ->whereExists(function ($grantQuery) use ($clinic): void {
                $grantQuery->selectRaw('1')
                    ->from('membership_clinic')
                    ->whereColumn('membership_clinic.membership_id', 'memberships.id')
                    ->where('membership_clinic.tenant_id', $clinic->tenant_id)
                    ->where('membership_clinic.clinic_id', $clinic->getKey())
                    ->where('membership_clinic.is_active', true);
            })
            ->exists();

        abort_unless($allowed, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
