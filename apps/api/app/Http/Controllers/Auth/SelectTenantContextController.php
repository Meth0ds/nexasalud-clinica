<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\TenantAccessDeniedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SelectTenantContextRequest;
use App\Models\Clinic;
use App\Models\Membership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Selects an authorized tenant membership and clinic for the authenticated session.
 */
class SelectTenantContextController extends Controller
{
    /**
     * Persist an ownership-checked tenant and clinic context, then rotate the session identifier.
     */
    public function __invoke(SelectTenantContextRequest $request): JsonResponse
    {
        $user = $request->user('web');
        $membershipId = $request->validated('membershipId');
        $clinicId = $request->validated('clinicId');
        $membership = Membership::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($membershipId)
            ->where('user_id', $user?->getAuthIdentifier())
            ->where('is_active', true)
            ->whereHas('tenant', fn (Builder $tenantQuery): Builder => $tenantQuery->where('is_active', true))
            ->first();

        if (! $membership instanceof Membership) {
            throw new TenantAccessDeniedException;
        }

        $clinic = Clinic::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($clinicId)
            ->where('tenant_id', $membership->tenant_id)
            ->where('is_active', true)
            ->whereExists(function ($grantQuery) use ($membership): void {
                $grantQuery->selectRaw('1')
                    ->from('membership_clinic')
                    ->whereColumn('membership_clinic.clinic_id', 'clinics.id')
                    ->where('membership_clinic.membership_id', $membership->getKey())
                    ->where('membership_clinic.tenant_id', $membership->tenant_id)
                    ->where('membership_clinic.is_active', true);
            })
            ->first();

        if (! $clinic instanceof Clinic) {
            throw new TenantAccessDeniedException;
        }

        $request->session()->put([
            'auth.context.membership_id' => $membership->getKey(),
            'auth.context.clinic_id' => $clinic->getKey(),
        ]);
        $request->session()->regenerate(destroy: true);

        return response()->json([
            'selected' => true,
            'tenantId' => $membership->tenant_id,
            'clinicId' => $clinic->getKey(),
        ]);
    }
}
