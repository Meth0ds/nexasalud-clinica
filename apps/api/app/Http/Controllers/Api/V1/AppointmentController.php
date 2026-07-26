<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RescheduleAppointmentRequest;
use App\Http\Requests\Api\V1\StoreAppointmentRequest;
use App\Http\Requests\Api\V1\TransitionAppointmentRequest;
use App\Http\Requests\Api\V1\UpdateAppointmentRequest;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auditing\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class AppointmentController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Appointment::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
            'status' => ['nullable', Rule::enum(AppointmentStatus::class)],
            'clinicId' => ['nullable', 'string'],
            'patientId' => ['nullable', 'string'],
            'practitionerId' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $from = CarbonImmutable::parse($filters['from'] ?? now()->startOfDay());
        $to = CarbonImmutable::parse($filters['to'] ?? $from->addDays(14));
        abort_if($from->diffInDays($to) > 93, Response::HTTP_UNPROCESSABLE_ENTITY);

        $appointments = Appointment::query()
            ->with(['appointmentType', 'clinic', 'room', 'patient', 'clinician'])
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['clinicId']), fn ($query) => $query->where('clinic_id', $filters['clinicId']))
            ->when(isset($filters['patientId']), fn ($query) => $query->where('patient_id', $filters['patientId']))
            ->when(isset($filters['practitionerId']), fn ($query) => $query->where('clinician_user_id', $filters['practitionerId']))
            ->orderBy('starts_at')
            ->paginate((int) ($filters['perPage'] ?? 50));

        return AppointmentResource::collection($appointments);
    }

    public function store(StoreAppointmentRequest $request): AppointmentResource
    {
        $data = $request->validated();
        $tenant = $this->tenant();
        $actor = $this->actor($request);
        $type = AppointmentType::query()->findOrFail($data['appointmentTypeId']);
        $room = isset($data['roomId']) ? Room::query()->findOrFail($data['roomId']) : null;

        abort_if($type->requires_room && ! $room, Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if($room && $room->clinic_id !== $data['clinicId'], Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if($data['mode'] === 'video' && ! $type->allows_video, Response::HTTP_UNPROCESSABLE_ENTITY);

        $appointment = DB::transaction(function () use ($actor, $data, $tenant): Appointment {
            return Appointment::create([
                'tenant_id' => $tenant->getKey(),
                'clinic_id' => $data['clinicId'],
                'room_id' => $data['roomId'] ?? null,
                'appointment_type_id' => $data['appointmentTypeId'],
                'patient_id' => $data['patientId'],
                'clinician_user_id' => $data['practitionerId'],
                'starts_at' => CarbonImmutable::parse($data['startsAt'], $data['timezone'])->utc(),
                'ends_at' => CarbonImmutable::parse($data['endsAt'], $data['timezone'])->utc(),
                'channel' => $this->channel($data['mode']),
                'reason' => $data['reason'] ?? null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        });

        $this->audit->record($request, 'appointment.created', $appointment, [
            'status' => $appointment->status->value,
            'channel' => $appointment->channel,
        ]);

        return new AppointmentResource($this->load($appointment));
    }

    public function show(Request $request, Appointment $appointment): AppointmentResource
    {
        Gate::authorize('view', $appointment);
        $this->audit->record($request, 'appointment.viewed', $appointment);

        return new AppointmentResource($this->load($appointment));
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validated();

        $appointment = DB::transaction(function () use ($appointment, $data): Appointment {
            $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());
            $this->requireVersion($locked, (int) $data['version']);

            if (array_key_exists('mode', $data)) {
                $locked->channel = $this->channel($data['mode']);
            }
            if (array_key_exists('reason', $data)) {
                $locked->reason = $data['reason'];
            }

            $locked->lock_version++;
            $locked->updated_by = request()->user()?->getAuthIdentifier();
            $locked->save();

            return $locked;
        });

        $this->audit->record($request, 'appointment.updated', $appointment, ['version' => $appointment->lock_version]);

        return new AppointmentResource($this->load($appointment));
    }

    public function reschedule(RescheduleAppointmentRequest $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validated();
        $this->requireVersion($appointment, (int) $data['version']);

        $room = isset($data['roomId']) ? Room::query()->findOrFail($data['roomId']) : null;
        $clinician = isset($data['practitionerId']) ? User::query()->findOrFail($data['practitionerId']) : null;
        $actor = $this->actor($request);
        $rescheduled = $appointment->reschedule(
            CarbonImmutable::parse($data['startsAt'], $data['timezone'])->utc(),
            CarbonImmutable::parse($data['endsAt'], $data['timezone'])->utc(),
            $actor,
            $room,
            $clinician,
            $data['reason'],
        );

        $this->audit->record($request, 'appointment.rescheduled', $rescheduled, ['version' => $rescheduled->lock_version]);

        return new AppointmentResource($this->load($rescheduled));
    }

    public function transition(TransitionAppointmentRequest $request, Appointment $appointment): AppointmentResource
    {
        $data = $request->validated();
        $this->requireVersion($appointment, (int) $data['version']);
        $next = AppointmentStatus::from($data['status']);
        $transitioned = $appointment->transitionTo($next, $this->actor($request), $data['reason'] ?? null);

        $this->audit->record($request, 'appointment.status_changed', $transitioned, [
            'status' => $next->value,
            'version' => $transitioned->lock_version,
        ]);

        return new AppointmentResource($this->load($transitioned));
    }

    public function cancel(Request $request, Appointment $appointment): AppointmentResource
    {
        Gate::authorize('delete', $appointment);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $this->requireVersion($appointment, (int) $data['version']);
        abort_if($appointment->status === AppointmentStatus::Cancelled, Response::HTTP_CONFLICT);

        $cancelled = $appointment->transitionTo(
            AppointmentStatus::Cancelled,
            $this->actor($request),
            $data['reason'],
        );
        $this->audit->record($request, 'appointment.cancelled', $cancelled, ['version' => $cancelled->lock_version]);

        return new AppointmentResource($this->load($cancelled));
    }

    public function destroy(Request $request, Appointment $appointment): Response
    {
        Gate::authorize('delete', $appointment);
        abort_if($appointment->status === AppointmentStatus::Cancelled, Response::HTTP_CONFLICT);

        $cancelled = $appointment->transitionTo(
            AppointmentStatus::Cancelled,
            $this->actor($request),
            'Cancelación solicitada desde la agenda.',
        );
        $this->audit->record($request, 'appointment.cancelled', $cancelled);

        return response()->noContent();
    }

    private function load(Appointment $appointment): Appointment
    {
        return $appointment->load(['appointmentType', 'clinic', 'room', 'patient', 'clinician']);
    }

    private function channel(string $mode): string
    {
        return match ($mode) {
            'video' => 'video',
            'phone' => 'phone',
            default => 'in_person',
        };
    }

    private function requireVersion(Appointment $appointment, int $version): void
    {
        abort_if($appointment->lock_version !== $version, Response::HTTP_CONFLICT);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, Response::HTTP_UNAUTHORIZED);

        return $actor;
    }

    private function tenant(): Tenant
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        abort_unless($tenant instanceof Tenant, Response::HTTP_FORBIDDEN);

        return $tenant;
    }
}
