<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\WaitlistStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\WaitlistEntry;
use App\Support\Auditing\AuditRecorder;
use App\Support\Authorization\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user() && $this->permissions->allows($request->user(), 'appointments.read'), Response::HTTP_FORBIDDEN);
        $clinic = app()->bound('currentClinic') ? app('currentClinic') : null;
        abort_unless($clinic instanceof Clinic, Response::HTTP_FORBIDDEN);

        $from = now($clinic->timezone)->startOfDay()->utc();
        $to = now($clinic->timezone)->endOfDay()->utc();
        $appointments = Appointment::query()
            ->where('clinic_id', $clinic->getKey())
            ->where('starts_at', '<=', $to)
            ->where('ends_at', '>=', $from);
        $total = (clone $appointments)->count();
        $completed = (clone $appointments)->where('status', AppointmentStatus::Completed)->count();
        $cancelled = (clone $appointments)->whereIn('status', [AppointmentStatus::Cancelled, AppointmentStatus::NoShow])->count();
        $active = (clone $appointments)->whereIn('status', [
            AppointmentStatus::Scheduled,
            AppointmentStatus::Confirmed,
            AppointmentStatus::CheckedIn,
            AppointmentStatus::InProgress,
        ])->count();

        $dayAppointments = (clone $appointments)
            ->with(['patient', 'room', 'appointmentType'])
            ->orderBy('starts_at')
            ->limit(24)
            ->get()
            ->map(fn (Appointment $appointment): array => $this->appointmentPayload($appointment));

        $waitingList = WaitlistEntry::query()
            ->where('clinic_id', $clinic->getKey())
            ->where('status', WaitlistStatus::Waiting)
            ->count();

        $this->audit->record($request, 'dashboard.viewed', metadata: [
            'clinic_id' => $clinic->getKey(),
            'appointment_count' => $total,
            'waiting_list_count' => $waitingList,
        ]);

        return response()->json([
            'data' => [
                'date' => now($clinic->timezone)->toDateString(),
                'timezone' => $clinic->timezone,
                'clinic' => ['id' => $clinic->getKey(), 'name' => $clinic->name],
                'metrics' => [
                    'appointments' => $total,
                    'active' => $active,
                    'completed' => $completed,
                    'cancelledOrNoShow' => $cancelled,
                    'waitingList' => $waitingList,
                    'occupancyPercent' => $total === 0 ? 0 : (int) round((($total - $cancelled) / $total) * 100),
                ],
                'nextAppointments' => Appointment::query()
                    ->with(['patient', 'clinician', 'room', 'appointmentType'])
                    ->where('clinic_id', $clinic->getKey())
                    ->where('starts_at', '>=', now())
                    ->whereIn('status', [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed])
                    ->orderBy('starts_at')
                    ->limit(8)
                    ->get()
                    ->map(fn (Appointment $appointment): array => [
                        'id' => $appointment->getKey(),
                        'startsAt' => $appointment->starts_at?->toIso8601String(),
                        'patientInitials' => collect(explode(' ', trim((string) $appointment->patient?->first_name.' '.(string) $appointment->patient?->last_name)))
                            ->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->join(''),
                        'practitionerName' => $appointment->clinician?->display_name,
                        'roomName' => $appointment->room?->name,
                        'typeName' => $appointment->appointmentType?->name,
                        'status' => $appointment->status->value,
                    ]),
                'dayAppointments' => $dayAppointments,
            ],
        ]);
    }

    /** @return array<string, string|null> */
    private function appointmentPayload(Appointment $appointment): array
    {
        $patientName = trim((string) $appointment->patient?->first_name.' '.(string) $appointment->patient?->last_name);

        return [
            'id' => (string) $appointment->getKey(),
            'startsAt' => $appointment->starts_at?->toIso8601String(),
            'endsAt' => $appointment->ends_at?->toIso8601String(),
            'patientDisplayName' => $patientName !== '' ? $patientName : 'Paciente no disponible',
            'patientInitials' => collect(explode(' ', $patientName))
                ->filter()
                ->take(2)
                ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                ->join(''),
            'roomName' => $appointment->room?->name,
            'typeName' => $appointment->appointmentType?->name,
            'status' => match ($appointment->status) {
                AppointmentStatus::Scheduled => 'pending',
                AppointmentStatus::Confirmed => 'confirmed',
                AppointmentStatus::CheckedIn => 'waiting',
                AppointmentStatus::InProgress => 'in-progress',
                AppointmentStatus::Completed => 'completed',
                AppointmentStatus::Cancelled => 'cancelled',
                AppointmentStatus::NoShow => 'no-show',
            },
        ];
    }
}
