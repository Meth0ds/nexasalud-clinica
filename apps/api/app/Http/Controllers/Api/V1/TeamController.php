<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Support\Authorization\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes the active tenant's staff directory without inventing availability or performance data.
 */
final class TeamController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * Returns identities, roles and operational membership state after server-side permission verification.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(
            $request->user() && (
                $this->permissions->allows($request->user(), 'team.read')
                || $this->permissions->allows($request->user(), 'appointments.read')
            ),
            Response::HTTP_FORBIDDEN,
        );

        $members = Membership::query()
            ->with(['user', 'roles'])
            ->orderByDesc('is_active')
            ->orderBy('joined_at')
            ->get()
            ->map(fn (Membership $membership): array => [
                'membershipId' => $membership->getKey(),
                'userId' => $membership->user_id,
                'displayName' => $membership->user?->display_name,
                'initials' => collect(explode(' ', (string) $membership->user?->display_name))
                    ->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->join(''),
                'roles' => $membership->roles->map(fn ($role): array => ['id' => $role->getKey(), 'code' => $role->code, 'name' => $role->name])->values(),
                'active' => $membership->is_active && (bool) $membership->user?->is_active,
                'joinedAt' => $membership->joined_at?->toIso8601String(),
                'lastLoginAt' => $membership->user?->last_login_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $members, 'meta' => ['total' => $members->count()]]);
    }
}
