<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Insurer;
use App\Models\Membership;
use App\Models\Room;
use App\Support\Authorization\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes active tenant-scoped reference data required by clinical scheduling workflows.
 */
final class ReferenceDataController extends Controller
{
    /**
     * Create the reference-data controller with server-side permission evaluation.
     */
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * Return authorized active reference data without exposing protected clinical records.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user() && $this->permissions->allows($request->user(), 'appointments.read'), Response::HTTP_FORBIDDEN);

        return response()->json(['data' => [
            'clinics' => Clinic::query()->where('is_active', true)->orderBy('name')->get()->map(fn (Clinic $clinic): array => [
                'id' => $clinic->getKey(), 'code' => $clinic->code, 'name' => $clinic->name, 'timezone' => $clinic->timezone,
            ]),
            'rooms' => Room::query()->where('is_active', true)->orderBy('name')->get()->map(fn (Room $room): array => [
                'id' => $room->getKey(), 'clinicId' => $room->clinic_id, 'name' => $room->name, 'capacity' => $room->capacity,
            ]),
            'appointmentTypes' => AppointmentType::query()->where('is_active', true)->orderBy('name')->get()->map(fn (AppointmentType $type): array => [
                'id' => $type->getKey(), 'code' => $type->code, 'name' => $type->name, 'durationMinutes' => $type->duration_minutes,
                'bufferBeforeMinutes' => $type->buffer_before_minutes, 'bufferAfterMinutes' => $type->buffer_after_minutes,
                'color' => $type->color, 'requiresRoom' => $type->requires_room, 'allowsVideo' => $type->allows_video,
            ]),
            'practitioners' => Membership::query()->with(['user', 'roles'])->where('is_active', true)->get()
                ->filter(fn (Membership $membership): bool => $membership->roles->contains(
                    fn ($role): bool => collect($role->permissions)->contains(
                        fn ($permission): bool => is_string($permission) && (str_starts_with($permission, 'prescriptions.') || $permission === '*'),
                    ),
                ))
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->user_id,
                    'displayName' => $membership->user?->display_name,
                    'roles' => $membership->roles->pluck('name')->values(),
                ])->values(),
            'insurers' => Insurer::query()->where('is_active', true)->orderBy('name')->get()->map(fn (Insurer $insurer): array => [
                'id' => $insurer->getKey(), 'code' => $insurer->code, 'name' => $insurer->name,
            ]),
        ]]);
    }
}
