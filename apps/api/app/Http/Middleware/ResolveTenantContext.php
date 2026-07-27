<?php

namespace App\Http\Middleware;

use App\Exceptions\TenantAccessDeniedException;
use App\Exceptions\TenantSelectionRequiredException;
use App\Models\Clinic;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves one authorized tenant membership and clinic for the request lifetime only.
 *
 * The middleware clears container context on every exit to prevent tenant state leaking into a
 * subsequent request handled by a long-lived worker.
 */
class ResolveTenantContext
{
    private const MEMBERSHIP_SESSION_KEY = 'auth.context.membership_id';

    private const CLINIC_SESSION_KEY = 'auth.context.clinic_id';

    /**
     * Resolves and registers the active tenant context before protected route execution.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->forgetContext();

        try {
            $user = $request->user('web');

            if (! $user instanceof User) {
                throw new AuthenticationException;
            }

            $membership = $this->resolveMembership($request, $user);
            $tenant = $membership->tenant;

            if (! $tenant instanceof Tenant || ! $tenant->is_active) {
                throw new TenantAccessDeniedException;
            }

            $clinic = $this->resolveClinic($request, $tenant);
            app()->instance('currentTenant', $tenant);
            app()->instance('currentMembership', $membership);
            app()->instance('currentClinic', $clinic);
            $membership->load(['roles' => fn ($query) => $query->orderBy('roles.name')]);

            return $next($request);
        } finally {
            $this->forgetContext();
        }
    }

    /**
     * Selects the user's only active membership or requires an explicit safe session selection.
     */
    private function resolveMembership(Request $request, User $user): Membership
    {
        $query = Membership::query()
            ->withoutGlobalScope('tenant')
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->whereHas('tenant', fn (Builder $tenantQuery): Builder => $tenantQuery->where('is_active', true))
            ->with('tenant');
        $selectedMembershipId = $request->session()->get(self::MEMBERSHIP_SESSION_KEY);

        if (is_string($selectedMembershipId) && $selectedMembershipId !== '') {
            $membership = (clone $query)->whereKey($selectedMembershipId)->first();

            if ($membership instanceof Membership) {
                return $membership;
            }

            $request->session()->forget([self::MEMBERSHIP_SESSION_KEY, self::CLINIC_SESSION_KEY]);
        }

        /** @var Collection<int, Membership> $memberships */
        $memberships = $query->orderBy('id')->limit(2)->get();

        if ($memberships->isEmpty()) {
            throw new TenantAccessDeniedException;
        }

        if ($memberships->count() > 1) {
            throw new TenantSelectionRequiredException('tenant');
        }

        $membership = $memberships->firstOrFail();
        $request->session()->put(self::MEMBERSHIP_SESSION_KEY, $membership->getKey());

        return $membership;
    }

    /**
     * Selects an active clinic within the resolved tenant without trusting arbitrary request input.
     */
    private function resolveClinic(Request $request, Tenant $tenant): Clinic
    {
        $query = Clinic::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->getKey())
            ->where('is_active', true);
        $selectedClinicId = $request->session()->get(self::CLINIC_SESSION_KEY);

        if (is_string($selectedClinicId) && $selectedClinicId !== '') {
            $clinic = (clone $query)->whereKey($selectedClinicId)->first();

            if ($clinic instanceof Clinic) {
                return $clinic;
            }

            $request->session()->forget(self::CLINIC_SESSION_KEY);
        }

        /** @var Collection<int, Clinic> $clinics */
        $clinics = $query->orderBy('id')->limit(2)->get();

        if ($clinics->isEmpty()) {
            throw new TenantAccessDeniedException;
        }

        if ($clinics->count() > 1) {
            throw new TenantSelectionRequiredException('clinic');
        }

        $clinic = $clinics->firstOrFail();
        $request->session()->put(self::CLINIC_SESSION_KEY, $clinic->getKey());

        return $clinic;
    }

    /**
     * Removes request-scoped tenant instances from the application container.
     */
    private function forgetContext(): void
    {
        app()->forgetInstance('currentClinic');
        app()->forgetInstance('currentMembership');
        app()->forgetInstance('currentTenant');
    }
}
