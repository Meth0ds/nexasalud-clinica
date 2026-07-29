<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Support\Auditing\AuditRecorder;
use App\Support\Authorization\PermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides privacy-preserving, tenant-scoped appointment aggregates.
 */
final class AnalyticsController extends Controller
{
    /**
     * Create the aggregate analytics controller with authorization and audit services.
     */
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Returns authorized appointment aggregates for a bounded time range.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(
            $request->user() && (
                $this->permissions->allows($request->user(), 'analytics.read')
                || $this->permissions->allows($request->user(), 'appointments.read')
            ),
            Response::HTTP_FORBIDDEN,
        );
        $clinic = app()->bound('currentClinic') ? app('currentClinic') : null;
        abort_unless($clinic instanceof Clinic, Response::HTTP_FORBIDDEN);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($filters['from'] ?? now($clinic->timezone)->subDays(29)->startOfDay())->utc();
        $to = CarbonImmutable::parse($filters['to'] ?? now($clinic->timezone)->endOfDay())->utc();
        abort_if($from->diffInDays($to) > 366, Response::HTTP_UNPROCESSABLE_ENTITY);

        $base = Appointment::query()
            ->where('clinic_id', $clinic->getKey())
            ->whereBetween('starts_at', [$from, $to]);
        $total = (clone $base)->count();
        $cancelled = (clone $base)->where('status', AppointmentStatus::Cancelled)->count();
        $noShow = (clone $base)->where('status', AppointmentStatus::NoShow)->count();
        $completed = (clone $base)->where('status', AppointmentStatus::Completed)->count();
        $byStatus = (clone $base)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        $this->audit->record($request, 'analytics.viewed', metadata: [
            'clinic_id' => $clinic->getKey(),
            'range_days' => $from->diffInDays($to) + 1,
            'appointment_count' => $total,
        ]);

        return response()->json(['data' => [
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'timezone' => $clinic->timezone],
            'metrics' => [
                'appointments' => $total,
                'completionRate' => $total === 0 ? 0 : round(($completed / $total) * 100, 1),
                'cancellationRate' => $total === 0 ? 0 : round(($cancelled / $total) * 100, 1),
                'noShowRate' => $total === 0 ? 0 : round(($noShow / $total) * 100, 1),
            ],
            'byStatus' => $byStatus,
            'privacy' => ['aggregated' => true, 'minimumGroupSize' => 5],
        ]]);
    }
}
