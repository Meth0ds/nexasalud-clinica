<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\WaitlistStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcceptWaitlistOfferRequest;
use App\Http\Requests\Api\V1\OfferWaitlistRequest;
use App\Http\Requests\Api\V1\RejectWaitlistOfferRequest;
use App\Http\Requests\Api\V1\StoreWaitlistRequest;
use App\Http\Resources\Api\V1\WaitlistEntryResource;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Auditing\AuditRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages tenant-scoped appointment waitlist entries with a controlled offer lifecycle and audit evidence.
 */
final class WaitlistController extends Controller
{
    /**
     * Create the waitlist controller with privacy-preserving audit recording.
     */
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Return authorized waitlist entries using validated, bounded filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WaitlistEntry::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(WaitlistStatus::class)],
            'clinicId' => ['nullable', 'string'],
            'patientId' => ['nullable', 'string'],
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $entries = WaitlistEntry::query()
            ->with(['patient', 'appointmentType', 'preferredClinician', 'clinic', 'fulfilledAppointment.appointmentType', 'fulfilledAppointment.clinic', 'fulfilledAppointment.room', 'fulfilledAppointment.clinician'])
            ->where('clinic_id', $this->currentClinic()->getKey())
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['clinicId']), fn ($query) => $query->where('clinic_id', $filters['clinicId']))
            ->when(isset($filters['patientId']), fn ($query) => $query->where('patient_id', $filters['patientId']))
            ->orderBy('priority')
            ->oldest('created_at')
            ->paginate((int) ($filters['perPage'] ?? 50));

        return WaitlistEntryResource::collection($entries);
    }

    /**
     * Create a tenant-scoped waitlist request from validated scheduling preferences.
     */
    public function store(StoreWaitlistRequest $request): WaitlistEntryResource
    {
        $data = $request->validated();
        $this->requireCurrentClinic($data['clinicId']);
        $this->requirePatientInCurrentClinic(Patient::query()->findOrFail($data['patientId']));
        if (isset($data['practitionerId'])) {
            $this->requirePractitionerInCurrentClinic($data['practitionerId']);
        }
        $entry = WaitlistEntry::create([
            'tenant_id' => $this->tenant()->getKey(),
            'clinic_id' => $data['clinicId'],
            'patient_id' => $data['patientId'],
            'appointment_type_id' => $data['appointmentTypeId'],
            'preferred_clinician_user_id' => $data['practitionerId'] ?? null,
            'desired_from' => CarbonImmutable::parse($data['desiredFrom'])->utc(),
            'desired_to' => CarbonImmutable::parse($data['desiredTo'])->utc(),
            'preferred_days' => $data['preferredDays'] ?? [],
            'priority' => $data['priority'],
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->record($request, 'waitlist.created', $entry, ['priority' => $entry->priority]);

        return new WaitlistEntryResource($this->load($entry));
    }

    /**
     * Return an authorized waitlist entry and record the sensitive access event.
     */
    public function show(Request $request, WaitlistEntry $waitlistEntry): WaitlistEntryResource
    {
        Gate::authorize('view', $waitlistEntry);
        $this->audit->record($request, 'waitlist.viewed', $waitlistEntry);

        return new WaitlistEntryResource($this->load($waitlistEntry));
    }

    /**
     * Update a waiting entry under a row lock and expected optimistic-lock version.
     */
    public function update(Request $request, WaitlistEntry $waitlistEntry): WaitlistEntryResource
    {
        Gate::authorize('update', $waitlistEntry);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'desiredFrom' => ['sometimes', 'date'],
            'desiredTo' => ['sometimes', 'date', 'after:desiredFrom'],
            'preferredDays' => ['sometimes', 'array', 'max:7'],
            'preferredDays.*' => ['integer', 'between:1,7', 'distinct'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $entry = DB::transaction(function () use ($data, $waitlistEntry): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($waitlistEntry->getKey());
            abort_if($entry->lock_version !== (int) $data['version'], Response::HTTP_CONFLICT);
            abort_unless($entry->status === WaitlistStatus::Waiting, Response::HTTP_CONFLICT);

            $map = [
                'desiredFrom' => 'desired_from',
                'desiredTo' => 'desired_to',
                'preferredDays' => 'preferred_days',
                'priority' => 'priority',
                'notes' => 'notes',
            ];
            foreach ($map as $input => $attribute) {
                if (array_key_exists($input, $data)) {
                    $entry->setAttribute($attribute, $data[$input]);
                }
            }
            $entry->lock_version++;
            $entry->save();

            return $entry;
        });

        $this->audit->record($request, 'waitlist.updated', $entry, ['version' => $entry->lock_version]);

        return new WaitlistEntryResource($this->load($entry));
    }

    /**
     * Create a time-bounded offer for a waiting entry, reserving the matched slot atomically.
     *
     * The offer either references an existing matching appointment or reserves a new
     * provisional appointment for the provided slot. The reserved slot is released when
     * the offer is rejected or expires.
     */
    public function offer(OfferWaitlistRequest $request, WaitlistEntry $waitlistEntry): WaitlistEntryResource
    {
        $data = $request->validated();
        $entry = DB::transaction(function () use ($data, $waitlistEntry, $request): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($waitlistEntry->getKey());
            abort_if($entry->lock_version !== (int) $data['version'], Response::HTTP_CONFLICT);
            abort_unless($entry->status === WaitlistStatus::Waiting, Response::HTTP_CONFLICT);
            $appointmentId = $this->resolveOfferAppointment($entry, $data, $request);
            $this->assertNotClaimedElsewhere($appointmentId, $entry);

            $entry->status = WaitlistStatus::Offered;
            $entry->offered_at = now();
            $entry->offer_expires_at = now()->addMinutes((int) $data['expiresInMinutes']);
            $entry->offer_seen_at = null;
            $entry->fulfilled_appointment_id = $appointmentId;
            $entry->lock_version++;
            $entry->save();

            return $entry;
        });

        $this->audit->record($request, 'waitlist.offer_created', $entry, [
            'expires_in_minutes' => (int) $data['expiresInMinutes'],
        ]);

        return new WaitlistEntryResource($this->load($entry));
    }

    /**
     * Accept an active offer, confirm the reserved appointment and mark the entry as booked.
     *
     * The acceptance is atomic: a stale or expired offer is rejected with a conflict and
     * the same appointment cannot be claimed by another active offer.
     */
    public function accept(AcceptWaitlistOfferRequest $request, WaitlistEntry $waitlistEntry): WaitlistEntryResource
    {
        $data = $request->validated();
        $entry = DB::transaction(function () use ($data, $waitlistEntry): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($waitlistEntry->getKey());
            abort_if($entry->lock_version !== (int) $data['version'], Response::HTTP_CONFLICT);
            abort_if(
                $entry->status === WaitlistStatus::Offered && $entry->offer_expires_at?->isPast(),
                Response::HTTP_CONFLICT,
                'La oferta ha caducado.',
            );
            abort_unless($entry->status === WaitlistStatus::Offered, Response::HTTP_CONFLICT);

            $appointmentId = $entry->fulfilled_appointment_id;
            abort_if(! is_string($appointmentId) || $appointmentId === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'La oferta no tiene una cita asociada.');

            $appointment = Appointment::query()->findOrFail($appointmentId);
            abort_unless(
                $appointment->patient_id === $entry->patient_id
                    && $appointment->clinic_id === $entry->clinic_id
                    && $appointment->appointment_type_id === $entry->appointment_type_id
                    && $appointment->status->blocksAvailability(),
                Response::HTTP_CONFLICT,
            );
            $this->assertNotClaimedElsewhere($appointmentId, $entry);

            $entry->status = WaitlistStatus::Booked;
            $entry->lock_version++;
            $entry->save();

            return $entry;
        });

        $this->audit->record($request, 'waitlist.offer_accepted', $entry);

        return new WaitlistEntryResource($this->load($entry));
    }

    /**
     * Reject an active offer, return the entry to waiting and release its reserved slot.
     */
    public function reject(RejectWaitlistOfferRequest $request, WaitlistEntry $waitlistEntry): WaitlistEntryResource
    {
        $data = $request->validated();
        $entry = DB::transaction(function () use ($data, $waitlistEntry): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($waitlistEntry->getKey());
            abort_if($entry->lock_version !== (int) $data['version'], Response::HTTP_CONFLICT);
            abort_if(
                $entry->status === WaitlistStatus::Offered && $entry->offer_expires_at?->isPast(),
                Response::HTTP_CONFLICT,
                'La oferta ha caducado.',
            );
            abort_unless($entry->status === WaitlistStatus::Offered, Response::HTTP_CONFLICT);

            $entry->releaseOwnedReservation($data['reason'] ?? 'Oferta de lista de espera rechazada');

            $entry->status = WaitlistStatus::Waiting;
            $entry->offered_at = null;
            $entry->offer_expires_at = null;
            $entry->offer_seen_at = null;
            $entry->fulfilled_appointment_id = null;
            $entry->offer_created_appointment = false;
            $entry->lock_version++;
            $entry->save();

            return $entry;
        });

        $this->audit->record($request, 'waitlist.offer_rejected', $entry);

        return new WaitlistEntryResource($this->load($entry));
    }

    /**
     * Expire an offered entry immediately and release its reserved slot.
     */
    public function expire(Request $request, WaitlistEntry $waitlistEntry): WaitlistEntryResource
    {
        Gate::authorize('update', $waitlistEntry);
        $entry = DB::transaction(function () use ($waitlistEntry): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($waitlistEntry->getKey());
            $this->expireOfferIfStale($entry, force: true);

            return $entry;
        });

        $this->audit->record($request, 'waitlist.offer_expired', $entry, ['status' => $entry->status->value]);

        return new WaitlistEntryResource($this->load($entry));
    }

    /**
     * Cancel an eligible waitlist entry without erasing its traceable history or leaking its slot.
     */
    public function destroy(Request $request, WaitlistEntry $waitlistEntry): Response
    {
        Gate::authorize('update', $waitlistEntry);
        $cancelled = DB::transaction(function () use ($waitlistEntry): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($waitlistEntry->getKey());
            abort_if(in_array($entry->status, [WaitlistStatus::Booked, WaitlistStatus::Cancelled], true), Response::HTTP_CONFLICT);

            if ($entry->status === WaitlistStatus::Offered) {
                $entry->releaseOwnedReservation('Solicitud cancelada por el personal');
            }
            $entry->forceFill([
                'status' => WaitlistStatus::Cancelled,
                'lock_version' => $entry->lock_version + 1,
            ])->save();

            return $entry;
        });
        $this->audit->record($request, 'waitlist.cancelled', $cancelled);

        return response()->noContent();
    }

    /**
     * Return an appointment identifier for the offer, creating a provisional appointment for a requested slot.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveOfferAppointment(WaitlistEntry $entry, array $data, Request $request): string
    {
        if (is_string($data['appointmentId'] ?? null) && $data['appointmentId'] !== '') {
            return $this->matchingAppointmentId($entry, $data['appointmentId']);
        }

        abort_unless(is_string($data['startsAt'] ?? null), Response::HTTP_UNPROCESSABLE_ENTITY, 'Indica el hueco concreto de la oferta.');
        $startsAt = CarbonImmutable::parse($data['startsAt'])->utc();
        $endsAt = CarbonImmutable::parse($data['endsAt'])->utc();
        abort_if(! $endsAt->isAfter($startsAt), Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if($startsAt->isBefore(now()), Response::HTTP_UNPROCESSABLE_ENTITY, 'El hueco ofrecido debe estar en el futuro.');
        abort_if(
            $startsAt->lt($entry->desired_from) || $endsAt->gt($entry->desired_to),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'El hueco ofrecido debe estar dentro de la ventana solicitada.',
        );

        $type = AppointmentType::query()->findOrFail($entry->appointment_type_id);
        $room = isset($data['roomId']) ? Room::query()->findOrFail($data['roomId']) : null;
        abort_if($room && $room->clinic_id !== $entry->clinic_id, Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if($type->requires_room && ! $room, Response::HTTP_UNPROCESSABLE_ENTITY, 'Este tipo de atención requiere una sala.');

        $mode = $data['mode'] ?? 'in-person';
        abort_if($mode === 'video' && ! $type->allows_video, Response::HTTP_UNPROCESSABLE_ENTITY);

        $clinicianId = is_string($data['practitionerId'] ?? null)
            ? $data['practitionerId']
            : $entry->preferred_clinician_user_id;
        abort_if(! is_string($clinicianId) || $clinicianId === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'Selecciona el profesional para la oferta.');
        $this->requirePractitionerInCurrentClinic($clinicianId);

        $appointment = Appointment::create([
            'tenant_id' => $entry->tenant_id,
            'clinic_id' => $entry->clinic_id,
            'room_id' => $room?->getKey(),
            'appointment_type_id' => $type->getKey(),
            'patient_id' => $entry->patient_id,
            'clinician_user_id' => $clinicianId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'channel' => $this->channel($mode),
            'reason' => 'Oferta de lista de espera',
            'created_by' => $this->actor($request)->getKey(),
            'updated_by' => $this->actor($request)->getKey(),
        ]);
        $entry->offer_created_appointment = true;

        return (string) $appointment->getKey();
    }

    /**
     * Return an appointment identifier only when it matches the waitlist patient, clinic and type.
     */
    private function matchingAppointmentId(WaitlistEntry $entry, mixed $appointmentId): string
    {
        if (! is_string($appointmentId) || $appointmentId === '') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'La oferta requiere una cita o un hueco concreto.');
        }

        $appointment = Appointment::query()->findOrFail($appointmentId);
        abort_unless(
            $appointment->patient_id === $entry->patient_id
                && $appointment->clinic_id === $entry->clinic_id
                && $appointment->appointment_type_id === $entry->appointment_type_id
                && $appointment->status->blocksAvailability(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        return (string) $appointment->getKey();
    }

    /**
     * Prevent two active offers from claiming the same reserved appointment.
     */
    private function assertNotClaimedElsewhere(string $appointmentId, WaitlistEntry $entry): void
    {
        $claimed = WaitlistEntry::query()
            ->where('fulfilled_appointment_id', $appointmentId)
            ->whereIn('status', [WaitlistStatus::Offered, WaitlistStatus::Booked])
            ->whereKeyNot($entry->getKey())
            ->exists();

        abort_if($claimed, Response::HTTP_CONFLICT, 'La cita ofrecida ya está asignada a otra solicitud.');
    }

    /**
     * Mark an expired offer as expired and release its provisional appointment.
     */
    private function expireOfferIfStale(WaitlistEntry $entry, bool $force = false): void
    {
        if ($entry->status !== WaitlistStatus::Offered) {
            return;
        }

        $isStale = $force || ($entry->offer_expires_at instanceof CarbonInterface && $entry->offer_expires_at->isPast());

        if (! $isStale) {
            return;
        }

        $entry->status = WaitlistStatus::Expired;
        $entry->lock_version++;
        $entry->save();
        $entry->releaseOwnedReservation('Oferta de lista de espera caducada');
        $entry->fulfilled_appointment_id = null;
        $entry->offer_created_appointment = false;
        $entry->lock_version++;
        $entry->save();
    }

    /**
     * Load only the relations required by an authorized waitlist response.
     */
    private function load(WaitlistEntry $entry): WaitlistEntry
    {
        return $entry->load([
            'patient',
            'appointmentType',
            'preferredClinician',
            'clinic',
            'fulfilledAppointment.appointmentType',
            'fulfilledAppointment.clinic',
            'fulfilledAppointment.room',
            'fulfilledAppointment.clinician',
        ]);
    }

    /**
     * Return the authenticated application user or reject anonymous waitlist mutations.
     */
    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, Response::HTTP_UNAUTHORIZED);

        return $actor;
    }

    /**
     * Map the validated offer mode to its persisted appointment channel value.
     */
    private function channel(string $mode): string
    {
        return $mode === 'in-person' ? 'in_person' : $mode;
    }

    /**
     * Return the active tenant context or reject contextless waitlist creation.
     */
    private function tenant(): Tenant
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        abort_unless($tenant instanceof Tenant, Response::HTTP_FORBIDDEN);

        return $tenant;
    }
}
