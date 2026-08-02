<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePortalBookingRequest;
use App\Http\Requests\Api\V1\CreateSlotHoldRequest;
use App\Http\Requests\Api\V1\ReschedulePortalAppointmentRequest;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Http\Resources\Api\V1\PortalMessageResource;
use App\Http\Resources\Api\V1\WaitlistEntryResource;
use App\Models\Appointment;
use App\Models\AppointmentSlotHold;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\PatientCommunication;
use App\Models\PatientUserLink;
use App\Models\PortalDemoRequest;
use App\Models\Prescription;
use App\Models\Room;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Documents\ClinicalPdfGenerator;
use App\Support\Auditing\AuditRecorder;
use App\Support\Authorization\PermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves patient-owned portal data and mutations through a verified, tenant-scoped patient link.
 */
final class PortalController extends Controller
{
    /**
     * Create the controller with portal authorization and audit dependencies.
     */
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditRecorder $audit,
        private readonly ClinicalPdfGenerator $pdfGenerator,
    ) {}

    /**
     * Return the linked patient's portal overview and audit the sensitive read.
     */
    public function overview(Request $request): JsonResponse
    {
        $link = $this->patientLink($request);
        $patient = $link->patient()->firstOrFail();
        $appointments = Appointment::query()
            ->with(['appointmentType', 'clinic', 'room', 'clinician'])
            ->where('patient_id', $patient->getKey())
            ->latest('starts_at')
            ->limit(100)
            ->get();
        $this->audit->record($request, 'portal.overview_viewed', $patient, ['relationship' => $link->relationship]);

        return response()->json(['data' => [
            'patient' => [
                'id' => $patient->getKey(),
                'displayName' => trim((string) $patient->first_name.' '.(string) $patient->last_name),
                'initials' => collect(explode(' ', trim((string) $patient->first_name.' '.(string) $patient->last_name)))
                    ->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->join(''),
                'relationship' => $link->relationship,
            ],
            'appointments' => $appointments->map(fn (Appointment $appointment): array => [
                'id' => $appointment->getKey(),
                'version' => $appointment->lock_version,
                'startsAt' => $appointment->starts_at?->toIso8601String(),
                'endsAt' => $appointment->ends_at?->toIso8601String(),
                'status' => $appointment->status->value,
                'mode' => $appointment->channel,
                'typeName' => $appointment->appointmentType?->name,
                'practitionerName' => $appointment->clinician?->display_name,
                'clinicName' => $appointment->clinic?->name,
                'roomName' => $appointment->room?->name,
                'canReschedule' => $appointment->status->blocksAvailability() && $appointment->starts_at?->isAfter(now()->addHours(4)),
                'canCancel' => in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed], true)
                    && $appointment->starts_at?->isAfter(now()->addHours(4)),
            ])->values(),
            'prescriptions' => $patient->prescriptions()->with(['items', 'prescriber'])->latest()->limit(50)->get()->map(fn ($prescription): array => [
                'id' => $prescription->getKey(),
                'status' => $prescription->status->value,
                'validUntil' => $prescription->valid_until?->toDateString(),
                'prescriberName' => $prescription->prescriber?->display_name,
                'itemCount' => $prescription->items->count(),
            ]),
            'documents' => $patient->documents()->where('status', 'ready')->latest('generated_at')->limit(50)->get()->map(fn ($document): array => [
                'id' => $document->getKey(),
                'type' => $document->document_type,
                'generatedAt' => $document->generated_at?->toIso8601String(),
                'revision' => $document->revision,
            ]),
            'invoices' => $patient->invoices()->latest()->limit(50)->get()->map(fn ($invoice): array => [
                'id' => $invoice->getKey(),
                'number' => $invoice->invoice_number,
                'status' => $invoice->status->value,
                'currency' => $invoice->currency,
                'totalMinor' => $invoice->total_minor,
                'balanceMinor' => $invoice->balance_minor,
            ]),
            'demoCapabilities' => [
                'medicationReview' => (bool) config('demo.portal_simulations'),
                'invoicePreview' => (bool) config('demo.portal_simulations'),
            ],
            'demoMedicationReviewPrescriptionIds' => (bool) config('demo.portal_simulations')
                ? PortalDemoRequest::query()
                    ->where('tenant_id', $link->tenant_id)
                    ->where('patient_id', $link->patient_id)
                    ->where('request_type', 'medication_review')
                    ->pluck('prescription_id')
                    ->values()
                    ->all()
                : [],
        ]]);
    }

    /**
     * Lists only queued portal communications for the verified patient and audits the sensitive read.
     */
    public function messages(Request $request): AnonymousResourceCollection
    {
        $link = $this->patientLink($request);
        $messages = PatientCommunication::query()
            ->with('author')
            ->where('patient_id', $link->patient_id)
            ->where('channel', CommunicationChannel::Portal)
            ->where('status', CommunicationStatus::Queued)
            ->latest('queued_at')
            ->limit(100)
            ->get();

        $this->audit->record($request, 'portal.messages_viewed', $link->patient, [
            'result_count' => $messages->count(),
        ]);

        return PortalMessageResource::collection($messages);
    }

    /**
     * Store one non-clinical medication-review simulation for the linked patient.
     */
    public function createDemoMedicationReview(Request $request, Prescription $prescription): JsonResponse
    {
        abort_unless((bool) config('demo.portal_simulations'), Response::HTTP_NOT_FOUND);

        $link = $this->patientLink($request);
        $created = false;
        $demoRequest = DB::transaction(function () use ($link, $prescription, &$created): PortalDemoRequest {
            $ownedPrescription = Prescription::query()
                ->whereKey($prescription->getKey())
                ->where('tenant_id', $link->tenant_id)
                ->where('patient_id', $link->patient_id)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = PortalDemoRequest::query()
                ->where('tenant_id', $link->tenant_id)
                ->where('patient_id', $link->patient_id)
                ->where('prescription_id', $ownedPrescription->getKey())
                ->where('request_type', 'medication_review')
                ->first();

            if ($existing instanceof PortalDemoRequest) {
                return $existing;
            }

            $created = true;

            return PortalDemoRequest::create([
                'tenant_id' => $link->tenant_id,
                'patient_id' => $link->patient_id,
                'prescription_id' => $ownedPrescription->getKey(),
                'request_type' => 'medication_review',
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
        });

        if ($created) {
            $this->audit->record($request, 'portal.demo_medication_review_requested', $demoRequest, [
                'simulated' => true,
            ]);
        }

        return response()->json(['data' => [
            'id' => $demoRequest->getKey(),
            'status' => $demoRequest->status,
            'simulated' => true,
            'submittedAt' => $demoRequest->submitted_at?->toIso8601String(),
        ]], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * Generate a protected, non-fiscal invoice preview for the linked patient demonstration.
     */
    public function generateDemoInvoicePreview(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless((bool) config('demo.portal_simulations'), Response::HTTP_NOT_FOUND);

        $link = $this->patientLink($request);
        $ownedInvoice = Invoice::query()
            ->whereKey($invoice->getKey())
            ->where('tenant_id', $link->tenant_id)
            ->where('patient_id', $link->patient_id)
            ->firstOrFail();
        $document = $this->pdfGenerator->generateDemoInvoicePreview($ownedInvoice, $this->actor($request));

        $this->audit->record($request, 'portal.demo_invoice_preview_generated', $document, [
            'simulated' => true,
        ]);

        return response()->json(['data' => [
            'id' => $document->getKey(),
            'documentType' => $document->document_type,
            'status' => $document->status->value,
            'revision' => $document->revision,
            'simulated' => true,
            'generatedAt' => $document->generated_at?->toIso8601String(),
            'downloadPath' => '/api/v1/documents/'.$document->getKey().'/download',
        ]], Response::HTTP_CREATED);
    }

    /**
     * List only waitlist entries owned by the verified patient and audit the sensitive read.
     */
    public function waitlist(Request $request): AnonymousResourceCollection
    {
        $link = $this->patientLink($request);
        $entries = WaitlistEntry::query()
            ->with(['patient', 'appointmentType'])
            ->where('patient_id', $link->patient_id)
            ->latest('created_at')
            ->limit(100)
            ->get();

        $this->audit->record($request, 'portal.waitlist_viewed', $link->patient, [
            'result_count' => $entries->count(),
        ]);

        return WaitlistEntryResource::collection($entries);
    }

    /**
     * Create a waiting-list request for the verified patient using conservative portal defaults.
     */
    public function createWaitlist(Request $request): WaitlistEntryResource
    {
        $link = $this->patientLink($request);
        $tenantId = (string) $link->tenant_id;
        $data = $request->validate([
            'clinicId' => ['required', 'string', Rule::exists('clinics', 'id')->where(fn (Builder $query) => $query->where('tenant_id', $tenantId)->where('is_active', true))],
            'appointmentTypeId' => ['required', 'string', Rule::exists('appointment_types', 'id')->where(fn (Builder $query) => $query->where('tenant_id', $tenantId)->where('is_active', true))],
            'desiredFrom' => ['required', 'date', 'after_or_equal:today'],
            'desiredTo' => ['required', 'date', 'after:desiredFrom'],
            'preferredDays' => ['nullable', 'array', 'max:7'],
            'preferredDays.*' => ['integer', 'between:1,7', 'distinct'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $clinic = Clinic::query()->findOrFail($data['clinicId']);
        $this->requireCurrentClinic($clinic);

        $entry = WaitlistEntry::create([
            'tenant_id' => $link->tenant_id,
            'clinic_id' => $clinic->getKey(),
            'patient_id' => $link->patient_id,
            'appointment_type_id' => $data['appointmentTypeId'],
            'desired_from' => CarbonImmutable::parse($data['desiredFrom'])->utc(),
            'desired_to' => CarbonImmutable::parse($data['desiredTo'])->utc(),
            'preferred_days' => $data['preferredDays'] ?? [],
            'priority' => 3,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->record($request, 'portal.waitlist_created', $entry, [
            'priority' => $entry->priority,
        ]);

        return new WaitlistEntryResource($entry->load(['patient', 'appointmentType']));
    }

    /**
     * Generate bookable appointment slots for the linked patient within the selected clinic context.
     */
    public function availability(Request $request): JsonResponse
    {
        $link = $this->patientLink($request);
        $tenantId = (string) $link->tenant_id;
        $data = $request->validate([
            'clinicId' => ['required', 'string', Rule::exists('clinics', 'id')->where(fn (Builder $query) => $query->where('tenant_id', $tenantId)->where('is_active', true))],
            'appointmentTypeId' => ['required', 'string', Rule::exists('appointment_types', 'id')->where(fn (Builder $query) => $query->where('tenant_id', $tenantId)->where('is_active', true))],
            'practitionerId' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'between:1,31'],
            'mode' => ['nullable', Rule::in(['in-person', 'video', 'phone'])],
        ]);
        $clinic = Clinic::query()->findOrFail($data['clinicId']);
        $this->requireCurrentClinic($clinic);
        $type = AppointmentType::query()->findOrFail($data['appointmentTypeId']);
        $mode = $data['mode'] ?? 'in-person';
        abort_if($mode === 'video' && ! $type->allows_video, Response::HTTP_UNPROCESSABLE_ENTITY);

        $from = CarbonImmutable::parse($data['from'] ?? now($clinic->timezone)->addDay(), $clinic->timezone)->startOfDay();
        abort_if($from->isBefore(now($clinic->timezone)->startOfDay()) || $from->isAfter(now($clinic->timezone)->addDays(90)), Response::HTTP_UNPROCESSABLE_ENTITY);
        $days = (int) ($data['days'] ?? 14);
        $providers = $this->clinicalMemberships($data['practitionerId'] ?? null);
        abort_if($providers->isEmpty(), Response::HTTP_UNPROCESSABLE_ENTITY);
        $room = $mode === 'in-person'
            ? Room::query()->where('clinic_id', $clinic->getKey())->where('is_active', true)->orderBy('name')->firstOrFail()
            : null;
        $slots = $this->generateSlots($link, $clinic, $type, $providers, $room, $from, $days, $mode);

        return response()->json(['data' => $slots, 'meta' => [
            'timezone' => $clinic->timezone,
            'generatedAt' => now()->toIso8601String(),
            'holdDurationSeconds' => 300,
            'count' => count($slots),
        ]]);
    }

    /**
     * Atomically reserve an available appointment slot for the authenticated portal user.
     */
    public function hold(CreateSlotHoldRequest $request): JsonResponse
    {
        $data = $request->validated();
        $link = $this->patientLink($request);
        $clinic = Clinic::query()->findOrFail($data['clinicId']);
        $this->requireCurrentClinic($clinic);
        $type = AppointmentType::query()->findOrFail($data['appointmentTypeId']);
        $provider = $this->clinicalMemberships($data['practitionerId'])->firstOrFail();
        $room = isset($data['roomId']) ? Room::query()->findOrFail($data['roomId']) : null;
        $startsAt = CarbonImmutable::parse($data['startsAt'])->utc();
        $endsAt = $startsAt->addMinutes($type->duration_minutes);
        $channel = $this->channel($data['mode']);

        abort_if($data['mode'] === 'in-person' && ! $room, Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if($room && $room->clinic_id !== $clinic->getKey(), Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if($data['mode'] === 'video' && ! $type->allows_video, Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertBookableTime($startsAt, $endsAt, $clinic);

        $lockName = sprintf('slot:%s:%s:%s', $link->tenant_id, $provider->user_id, hash('sha256', $startsAt->toIso8601String()));
        try {
            $hold = Cache::lock($lockName, 10)->block(3, function () use ($channel, $clinic, $endsAt, $link, $provider, $request, $room, $startsAt, $type): AppointmentSlotHold {
                return DB::transaction(function () use ($channel, $clinic, $endsAt, $link, $provider, $request, $room, $startsAt, $type): AppointmentSlotHold {
                    AppointmentSlotHold::query()->where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired']);
                    $this->assertSlotFree($link->patient_id, $provider->user_id, $room?->getKey(), $startsAt, $endsAt);

                    return AppointmentSlotHold::create([
                        'tenant_id' => $link->tenant_id,
                        'clinic_id' => $clinic->getKey(),
                        'appointment_type_id' => $type->getKey(),
                        'patient_id' => $link->patient_id,
                        'created_by_user_id' => $request->user()?->getAuthIdentifier(),
                        'clinician_user_id' => $provider->user_id,
                        'room_id' => $room?->getKey(),
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'channel' => $channel,
                        'expires_at' => now()->addMinutes(5),
                    ]);
                });
            });
        } catch (LockTimeoutException) {
            abort(Response::HTTP_CONFLICT, 'The selected slot is being processed.');
        }

        $this->audit->record($request, 'portal.slot_held', $hold, ['duration_seconds' => 300]);

        return response()->json(['data' => [
            'holdId' => $hold->getKey(),
            'startsAt' => $hold->starts_at?->toIso8601String(),
            'endsAt' => $hold->ends_at?->toIso8601String(),
            'expiresAt' => $hold->expires_at?->toIso8601String(),
            'version' => $hold->lock_version,
        ]], Response::HTTP_CREATED);
    }

    /**
     * Consume a patient-owned active hold and create the corresponding appointment.
     */
    public function book(CreatePortalBookingRequest $request): AppointmentResource
    {
        $data = $request->validated();
        $link = $this->patientLink($request);
        $actor = $this->actor($request);
        $appointment = DB::transaction(function () use ($actor, $data, $link): Appointment {
            $hold = AppointmentSlotHold::query()->lockForUpdate()->findOrFail($data['holdId']);
            $this->assertOwnedActiveHold($hold, $link, $actor);
            $this->assertSlotFree(
                $link->patient_id,
                $hold->clinician_user_id,
                $hold->room_id,
                $hold->starts_at,
                $hold->ends_at,
                $hold->getKey(),
            );

            $hold->status = 'consumed';
            $hold->consumed_at = now();
            $hold->lock_version++;
            $hold->save();

            $appointment = Appointment::create([
                'tenant_id' => $link->tenant_id,
                'clinic_id' => $hold->clinic_id,
                'room_id' => $hold->room_id,
                'appointment_type_id' => $hold->appointment_type_id,
                'patient_id' => $link->patient_id,
                'clinician_user_id' => $hold->clinician_user_id,
                'starts_at' => $hold->starts_at,
                'ends_at' => $hold->ends_at,
                'channel' => $hold->channel,
                'reason' => $data['reason'],
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $hold->appointment_id = $appointment->getKey();
            $hold->save();

            return $appointment;
        });

        $this->audit->record($request, 'portal.appointment_booked', $appointment, ['source' => 'patient_portal']);

        return new AppointmentResource($appointment->load(['appointmentType', 'clinic', 'room', 'patient', 'clinician']));
    }

    /**
     * Cancel a future appointment owned by the linked patient using optimistic concurrency.
     */
    public function cancel(Request $request, Appointment $appointment): AppointmentResource
    {
        $link = $this->patientLink($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $this->assertPortalAppointment($appointment, $link, (int) $data['version']);
        abort_unless(in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed], true), Response::HTTP_CONFLICT);
        abort_unless($appointment->starts_at?->isAfter(now()->addHours(4)), Response::HTTP_CONFLICT);

        $cancelled = $appointment->transitionTo(
            AppointmentStatus::Cancelled,
            $this->actor($request),
            $data['reason'],
            (int) $data['version'],
        );
        $this->audit->record($request, 'portal.appointment_cancelled', $cancelled, ['source' => 'patient_portal']);

        return new AppointmentResource($cancelled->load(['appointmentType', 'clinic', 'room', 'patient', 'clinician']));
    }

    /**
     * Reschedule a future patient-owned appointment to a verified active slot hold.
     */
    public function reschedule(ReschedulePortalAppointmentRequest $request, Appointment $appointment): AppointmentResource
    {
        $link = $this->patientLink($request);
        $actor = $this->actor($request);
        $data = $request->validated();
        $this->assertPortalAppointment($appointment, $link, (int) $data['version']);
        abort_unless($appointment->starts_at?->isAfter(now()->addHours(4)), Response::HTTP_CONFLICT);

        $rescheduled = DB::transaction(function () use ($actor, $appointment, $data, $link): Appointment {
            $hold = AppointmentSlotHold::query()->lockForUpdate()->findOrFail($data['holdId']);
            $this->assertOwnedActiveHold($hold, $link, $actor);
            $hold->status = 'consumed';
            $hold->consumed_at = now();
            $hold->lock_version++;
            $hold->save();

            $room = $hold->room_id ? Room::query()->findOrFail($hold->room_id) : null;
            $clinician = User::query()->findOrFail($hold->clinician_user_id);
            $updated = $appointment->reschedule(
                $hold->starts_at,
                $hold->ends_at,
                $actor,
                $room,
                $clinician,
                $data['reason'],
                (int) $data['version'],
            );
            $hold->appointment_id = $updated->getKey();
            $hold->save();

            return $updated;
        });

        $this->audit->record($request, 'portal.appointment_rescheduled', $rescheduled, ['source' => 'patient_portal']);

        return new AppointmentResource($rescheduled->load(['appointmentType', 'clinic', 'room', 'patient', 'clinician']));
    }

    /**
     * Resolve the authenticated actor's preferred active, unexpired patient portal link.
     */
    private function patientLink(Request $request): PatientUserLink
    {
        $actor = $this->actor($request);
        abort_unless($this->permissions->allows($actor, 'portal.self'), Response::HTTP_FORBIDDEN);

        return PatientUserLink::query()
            ->with('patient')
            ->where('user_id', $actor->getKey())
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('access_expires_at')->orWhere('access_expires_at', '>', now()))
            ->orderByRaw("CASE WHEN relationship = 'self' THEN 0 ELSE 1 END")
            ->firstOrFail();
    }

    /**
     * Return active clinical memberships optionally restricted to one practitioner.
     *
     * @return Collection<int, Membership>
     */
    private function clinicalMemberships(?string $userId): Collection
    {
        return Membership::query()
            ->with(['user', 'roles'])
            ->where('is_active', true)
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->get()
            ->filter(fn (Membership $membership): bool => $membership->user?->is_active === true && $membership->roles->contains(
                fn ($role): bool => collect($role->permissions)->contains(
                    fn ($permission): bool => is_string($permission) && (
                        $permission === '*'
                        || $permission === 'appointments.write'
                        || $permission === 'appointments.*'
                        || $permission === 'prescriptions.write'
                        || $permission === 'prescriptions.*'
                    ),
                ),
            ))->values();
    }

    /**
     * Build non-conflicting weekday slots across the requested provider and clinic range.
     *
     * @param  Collection<int, Membership>  $providers
     * @return list<array<string, mixed>>
     */
    private function generateSlots(
        PatientUserLink $link,
        Clinic $clinic,
        AppointmentType $type,
        Collection $providers,
        ?Room $room,
        CarbonImmutable $from,
        int $days,
        string $mode,
    ): array {
        AppointmentSlotHold::query()->where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired']);
        $rangeEnd = $from->addDays($days)->endOfDay()->utc();
        $rangeStart = $from->utc();
        $providerIds = $providers->pluck('user_id')->all();
        $appointments = Appointment::query()
            ->whereIn('clinician_user_id', $providerIds)
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->whereIn('status', array_map(fn (AppointmentStatus $status): string => $status->value, array_filter(AppointmentStatus::cases(), fn (AppointmentStatus $status): bool => $status->blocksAvailability())))
            ->get(['patient_id', 'clinician_user_id', 'room_id', 'starts_at', 'ends_at']);
        $holds = AppointmentSlotHold::query()
            ->where('status', 'active')->where('starts_at', '<', $rangeEnd)->where('ends_at', '>', $rangeStart)
            ->get(['patient_id', 'clinician_user_id', 'room_id', 'starts_at', 'ends_at']);
        $occupied = $appointments->concat($holds);
        $slots = [];

        for ($dayOffset = 0; $dayOffset < $days && count($slots) < 120; $dayOffset++) {
            $day = $from->addDays($dayOffset);
            if ($day->isWeekend()) {
                continue;
            }
            foreach ($providers as $provider) {
                $cursor = $day->setTime(8, 0);
                $lastStart = $day->setTime(18, 0)->subMinutes($type->duration_minutes);
                while ($cursor->lessThanOrEqualTo($lastStart) && count($slots) < 120) {
                    $start = $cursor->utc();
                    $end = $start->addMinutes($type->duration_minutes);
                    $conflict = $start->isBefore(now()->addHours(4)) || $occupied->contains(
                        fn ($entry): bool => $entry->starts_at->lt($end)
                            && $entry->ends_at->gt($start)
                            && ($entry->patient_id === $link->patient_id || $entry->clinician_user_id === $provider->user_id || ($room && $entry->room_id === $room->getKey())),
                    );
                    if (! $conflict) {
                        $slots[] = [
                            'clinicId' => $clinic->getKey(),
                            'appointmentTypeId' => $type->getKey(),
                            'practitionerId' => $provider->user_id,
                            'practitionerName' => $provider->user?->display_name,
                            'roomId' => $room?->getKey(),
                            'roomName' => $room?->name,
                            'startsAt' => $start->toIso8601String(),
                            'endsAt' => $end->toIso8601String(),
                            'mode' => $mode,
                        ];
                    }
                    $cursor = $cursor->addMinutes(max(15, $type->duration_minutes));
                }
            }
        }

        return $slots;
    }

    /**
     * Abort when an appointment or an active hold conflicts with the requested resources and period.
     */
    private function assertSlotFree(
        string $patientId,
        string $clinicianId,
        ?string $roomId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $excludedHoldId = null,
    ): void {
        $appointmentConflict = Appointment::query()
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->whereIn('status', array_map(fn (AppointmentStatus $status): string => $status->value, array_filter(AppointmentStatus::cases(), fn (AppointmentStatus $status): bool => $status->blocksAvailability())))
            ->where(fn ($query) => $query->where('patient_id', $patientId)->orWhere('clinician_user_id', $clinicianId)->when($roomId, fn ($query) => $query->orWhere('room_id', $roomId)))
            ->exists();
        $holdConflict = AppointmentSlotHold::query()
            ->where('status', 'active')->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($excludedHoldId, fn ($query) => $query->whereKeyNot($excludedHoldId))
            ->where(fn ($query) => $query->where('patient_id', $patientId)->orWhere('clinician_user_id', $clinicianId)->when($roomId, fn ($query) => $query->orWhere('room_id', $roomId)))
            ->exists();
        abort_if($appointmentConflict || $holdConflict, Response::HTTP_CONFLICT);
    }

    /**
     * Ensure that a hold belongs to the linked patient and authenticated actor and has not expired.
     */
    private function assertOwnedActiveHold(AppointmentSlotHold $hold, PatientUserLink $link, User $actor): void
    {
        abort_if($hold->patient_id !== $link->patient_id || $hold->created_by_user_id !== $actor->getKey(), Response::HTTP_NOT_FOUND);
        if ($hold->status === 'active' && $hold->expires_at?->isPast()) {
            $hold->status = 'expired';
            $hold->save();
        }
        abort_unless($hold->status === 'active' && $hold->expires_at?->isFuture(), Response::HTTP_CONFLICT);
    }

    /**
     * Ensure that an appointment belongs to the linked patient and matches the submitted version.
     */
    private function assertPortalAppointment(Appointment $appointment, PatientUserLink $link, int $version): void
    {
        abort_if($appointment->patient_id !== $link->patient_id, Response::HTTP_NOT_FOUND);
        abort_if($appointment->lock_version !== $version, Response::HTTP_CONFLICT);
    }

    /**
     * Ensure that a requested slot falls within the clinic's permitted weekday booking window.
     */
    private function assertBookableTime(CarbonImmutable $start, CarbonImmutable $end, Clinic $clinic): void
    {
        $localStart = $start->setTimezone($clinic->timezone);
        $localEnd = $end->setTimezone($clinic->timezone);
        abort_if($localStart->isWeekend() || $localStart->isBefore(now($clinic->timezone)->addHours(4)), Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if($localStart->format('H:i') < '08:00' || $localEnd->format('H:i') > '18:00' || $localStart->toDateString() !== $localEnd->toDateString(), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Ensure that the selected clinic matches the request's tenant-scoped clinic context.
     */
    private function requireCurrentClinic(Clinic $clinic): void
    {
        $current = app()->bound('currentClinic') ? app('currentClinic') : null;
        abort_unless($current instanceof Clinic && $current->is($clinic), Response::HTTP_FORBIDDEN);
    }

    /**
     * Convert the portal's in-person mode identifier to the persisted appointment channel value.
     */
    private function channel(string $mode): string
    {
        return $mode === 'in-person' ? 'in_person' : $mode;
    }

    /**
     * Return the authenticated user or abort the request when no application user is present.
     */
    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, Response::HTTP_UNAUTHORIZED);

        return $actor;
    }
}
