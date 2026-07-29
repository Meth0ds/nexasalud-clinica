<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\TenantAccessDeniedException;
use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lists active tenant memberships and clinics that the authenticated user may select.
 */
class TenantContextOptionsController extends Controller
{
    /**
     * Return bounded context-selection options without exposing memberships outside the current user.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Collection<int, Membership> $memberships */
        $memberships = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('user_id', $request->user('web')?->getAuthIdentifier())
            ->where('is_active', true)
            ->whereHas('tenant', fn (Builder $tenantQuery): Builder => $tenantQuery->where('is_active', true))
            ->with('tenant')
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($memberships->isEmpty()) {
            throw new TenantAccessDeniedException;
        }

        /** @var Collection<int, Clinic> $clinics */
        $clinics = Clinic::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('tenant_id', $memberships->pluck('tenant_id')->all())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $clinicsByTenant = $clinics->groupBy('tenant_id');
        $selectedMembershipId = $request->session()->get('auth.context.membership_id');
        $selectedClinicId = $request->session()->get('auth.context.clinic_id');
        $selectedMembership = is_string($selectedMembershipId)
            ? $memberships->firstWhere('id', $selectedMembershipId)
            : null;

        if (! $selectedMembership instanceof Membership) {
            $selectedMembershipId = null;
            $selectedClinicId = null;
        } elseif (
            ! is_string($selectedClinicId) ||
            ! $clinicsByTenant->get($selectedMembership->tenant_id, collect())->contains('id', $selectedClinicId)
        ) {
            $selectedClinicId = null;
        }

        return response()->json([
            'items' => $memberships->map(function (Membership $membership) use ($clinicsByTenant): array {
                $tenant = $membership->tenant;

                if (! $tenant instanceof Tenant) {
                    throw new TenantAccessDeniedException;
                }

                return [
                    'membershipId' => $membership->getKey(),
                    'tenant' => [
                        'id' => $tenant->getKey(),
                        'name' => $tenant->name,
                    ],
                    'clinics' => $clinicsByTenant->get($tenant->getKey(), collect())
                        ->map(static fn (Clinic $clinic): array => [
                            'id' => $clinic->getKey(),
                            'name' => $clinic->name,
                        ])
                        ->values()
                        ->all(),
                ];
            })->values()->all(),
            'selected' => [
                'membershipId' => $selectedMembershipId,
                'clinicId' => $selectedClinicId,
            ],
        ]);
    }
}
