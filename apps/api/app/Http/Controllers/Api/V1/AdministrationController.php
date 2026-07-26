<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Support\Auditing\AuditRecorder;
use App\Support\Authorization\PermissionService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class AdministrationController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditRecorder $audit,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $this->authorize($request, 'admin.users.read');
        $memberships = Membership::query();

        return response()->json(['data' => [
            'users' => [
                'total' => (clone $memberships)->count(),
                'active' => (clone $memberships)->where('is_active', true)->count(),
                'suspended' => (clone $memberships)->where('is_active', false)->count(),
            ],
            'roles' => Role::query()->count(),
            'auditEventsLast24Hours' => AuditEvent::query()->where('occurred_at', '>=', now()->subDay())->count(),
            'mfaPolicy' => ['required' => true, 'methods' => ['totp'], 'minimumAcr' => 2],
        ]]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->authorize($request, 'admin.users.read');
        $filters = $request->validate([
            'active' => ['nullable', 'boolean'],
            'roleId' => ['nullable', 'string', Rule::exists('roles', 'id')->where(fn (Builder $query) => $query->where('tenant_id', $this->tenantId()))],
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $memberships = Membership::query()
            ->with(['user', 'roles'])
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', (bool) $filters['active']))
            ->when(isset($filters['roleId']), fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->whereKey($filters['roleId'])))
            ->orderByDesc('is_active')
            ->paginate((int) ($filters['perPage'] ?? 50));

        return response()->json([
            'data' => $memberships->getCollection()->map(fn (Membership $membership): array => $this->membershipData($membership))->values(),
            'meta' => [
                'currentPage' => $memberships->currentPage(),
                'lastPage' => $memberships->lastPage(),
                'perPage' => $memberships->perPage(),
                'total' => $memberships->total(),
            ],
        ]);
    }

    public function roles(Request $request): JsonResponse
    {
        $this->authorize($request, 'admin.users.read');
        $roles = Role::query()->withCount('memberships')->orderBy('name')->get()->map(fn (Role $role): array => [
            'id' => $role->getKey(),
            'code' => $role->code,
            'name' => $role->name,
            'permissions' => $role->permissions,
            'system' => $role->is_system,
            'memberCount' => $role->memberships_count,
        ]);

        return response()->json(['data' => $roles]);
    }

    public function updateMembership(Request $request, string $user): JsonResponse
    {
        $this->authorize($request, 'admin.users.write');
        $this->requireRecentMfa($request);
        $data = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'roleIds' => ['sometimes', 'array', 'max:20'],
            'roleIds.*' => ['string', 'distinct', Rule::exists('roles', 'id')->where(fn (Builder $query) => $query->where('tenant_id', $this->tenantId()))],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, Response::HTTP_UNAUTHORIZED);

        $membership = DB::transaction(function () use ($actor, $data, $user): Membership {
            $membership = Membership::query()->where('user_id', $user)->lockForUpdate()->firstOrFail();
            abort_if($membership->user_id === $actor->getKey() && ($data['active'] ?? true) === false, Response::HTTP_CONFLICT);

            if (array_key_exists('active', $data)) {
                $membership->is_active = (bool) $data['active'];
                $membership->save();
            }
            if (array_key_exists('roleIds', $data)) {
                $pivot = collect($data['roleIds'])->mapWithKeys(fn (string $roleId): array => [$roleId => [
                    'tenant_id' => $membership->tenant_id,
                    'granted_by' => $actor->getKey(),
                    'granted_at' => now(),
                ]])->all();
                $membership->roles()->sync($pivot);
            }

            return $membership->load(['user', 'roles']);
        });

        $this->audit->record($request, 'administration.membership_updated', $membership, [
            'active' => $membership->is_active,
            'role_count' => $membership->roles->count(),
            'reason_recorded' => true,
        ]);

        return response()->json(['data' => $this->membershipData($membership)]);
    }

    public function revokeSessions(Request $request, string $user): JsonResponse
    {
        $this->authorize($request, 'admin.security.write');
        $this->requireRecentMfa($request);
        $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);
        $membership = Membership::query()->with('user')->where('user_id', $user)->firstOrFail();
        abort_if($membership->user_id === $request->user()?->getAuthIdentifier(), Response::HTTP_CONFLICT);

        $membership->user?->forceFill(['sessions_revoked_at' => now()])->save();
        $this->audit->record($request, 'administration.sessions_revoked', $membership, ['reason_recorded' => true]);

        return response()->json(['data' => ['revoked' => true, 'effectiveAt' => now()->toIso8601String()]]);
    }

    /** @return array<string, mixed> */
    private function membershipData(Membership $membership): array
    {
        $email = (string) $membership->user?->email;
        $emailHint = str_contains($email, '@') ? mb_substr($email, 0, 1).'•••@'.explode('@', $email, 2)[1] : null;

        return [
            'membershipId' => $membership->getKey(),
            'userId' => $membership->user_id,
            'displayName' => $membership->user?->display_name,
            'emailHint' => $emailHint,
            'active' => $membership->is_active && (bool) $membership->user?->is_active,
            'roles' => $membership->roles->map(fn (Role $role): array => ['id' => $role->getKey(), 'code' => $role->code, 'name' => $role->name])->values(),
            'lastLoginAt' => $membership->user?->last_login_at?->toIso8601String(),
            'sessionsRevokedAt' => $membership->user?->sessions_revoked_at?->toIso8601String(),
            'mfaPolicy' => 'required',
        ];
    }

    private function authorize(Request $request, string $permission): void
    {
        abort_unless($request->user() instanceof User && $this->permissions->allows($request->user(), $permission), Response::HTTP_FORBIDDEN);
    }

    private function requireRecentMfa(Request $request): void
    {
        $methods = $request->session()->get('auth.assurance.amr', []);
        $authenticatedAt = $request->session()->get('auth.assurance.authenticated_at');
        abort_unless(
            is_array($methods)
            && array_intersect($methods, ['mfa', 'otp', 'totp', 'webauthn', 'fido']) !== []
            && is_int($authenticatedAt)
            && $authenticatedAt >= now()->subMinutes(15)->getTimestamp(),
            Response::HTTP_FORBIDDEN,
        );
    }

    private function tenantId(): string
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        abort_unless($tenant instanceof \App\Models\Tenant, Response::HTTP_FORBIDDEN);

        return (string) $tenant->getKey();
    }
}
