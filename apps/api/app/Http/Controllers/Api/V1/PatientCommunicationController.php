<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationContentClass;
use App\Enums\CommunicationStatus;
use App\Enums\ConsentStatus;
use App\Events\CommunicationQueued;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelPatientCommunicationRequest;
use App\Http\Requests\Api\V1\QueuePatientCommunicationRequest;
use App\Http\Requests\Api\V1\StorePatientCommunicationRequest;
use App\Http\Resources\Api\V1\PatientCommunicationResource;
use App\Models\ConsentRecord;
use App\Models\Patient;
use App\Models\PatientCommunication;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auditing\AuditRecorder;
use App\Support\Authorization\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages tenant-scoped patient communications without exposing clinical content to external channels.
 */
final class PatientCommunicationController extends Controller
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * Lists communications that the authenticated actor may read in the active tenant.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PatientCommunication::class);
        $filters = $request->validate([
            'patientId' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(CommunicationStatus::class)],
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $communications = PatientCommunication::query()
            ->with(['patient', 'author'])
            ->where('clinic_id', $this->currentClinic()->getKey())
            ->when(isset($filters['patientId']), fn ($query) => $query->where('patient_id', $filters['patientId']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(
                ! $this->permissions->allows($this->actor($request), 'communications.clinical.read'),
                fn ($query) => $query->where('content_class', CommunicationContentClass::Administrative),
            )
            ->latest('created_at')
            ->paginate((int) ($filters['perPage'] ?? 50));

        $this->audit->record($request, 'communication.timeline_viewed', metadata: [
            'result_count' => $communications->count(),
        ]);

        return PatientCommunicationResource::collection($communications);
    }

    /**
     * Returns one authorized communication and records the sensitive read operation.
     */
    public function show(Request $request, PatientCommunication $communication): PatientCommunicationResource
    {
        Gate::authorize('view', $communication);
        $this->audit->record($request, 'communication.viewed', $communication);

        return new PatientCommunicationResource($this->load($communication));
    }

    /**
     * Creates a draft or queues a compliant communication after validating content and consent boundaries.
     */
    public function store(StorePatientCommunicationRequest $request): PatientCommunicationResource
    {
        $data = $request->validated();
        $actor = $this->actor($request);
        $contentClass = CommunicationContentClass::from((string) $data['contentClass']);
        $this->assertCanWriteContentClass($actor, $contentClass);
        $patient = Patient::query()->findOrFail($data['patientId']);
        $this->requirePatientInCurrentClinic($patient);
        $clinic = $this->currentClinic();

        $communication = DB::transaction(function () use ($actor, $contentClass, $data, $patient, $clinic): PatientCommunication {
            $communication = PatientCommunication::create([
                'tenant_id' => $this->tenant()->getKey(),
                'clinic_id' => $clinic->getKey(),
                'patient_id' => $patient->getKey(),
                'author_user_id' => $actor->getKey(),
                'channel' => $data['channel'],
                'content_class' => $contentClass,
                'template_key' => $data['templateKey'] ?? null,
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'] ?? null,
                'scheduled_at' => $data['scheduledAt'] ?? null,
            ]);

            if ($data['mode'] === 'queue') {
                $this->queueLocked($communication, $data['scheduledAt'] ?? null);
            }

            return $communication;
        });

        $this->audit->record($request, $communication->status === CommunicationStatus::Queued
            ? 'communication.queued'
            : 'communication.drafted', $communication, [
                'channel' => $communication->channel->value,
                'content_class' => $communication->content_class->value,
                'uses_external_template' => $communication->channel->requiresTemplate(),
            ]);
        if ($communication->status === CommunicationStatus::Queued) {
            $this->dispatchQueuedEvent($request, $communication, $actor);
        }

        return new PatientCommunicationResource($this->load($communication));
    }

    /**
     * Queues a draft using an optimistic version check so a stale actor cannot alter its delivery state.
     */
    public function queue(QueuePatientCommunicationRequest $request, PatientCommunication $communication): PatientCommunicationResource
    {
        $data = $request->validated();
        $queued = DB::transaction(function () use ($communication, $data): PatientCommunication {
            $locked = PatientCommunication::query()->lockForUpdate()->findOrFail($communication->getKey());
            abort_if($locked->lock_version !== (int) $data['version'], Response::HTTP_CONFLICT);
            abort_unless($locked->status === CommunicationStatus::Draft, Response::HTTP_CONFLICT);
            $this->queueLocked($locked, $data['scheduledAt'] ?? null);

            return $locked;
        });

        $this->audit->record($request, 'communication.queued', $queued, [
            'channel' => $queued->channel->value,
            'content_class' => $queued->content_class->value,
            'uses_external_template' => $queued->channel->requiresTemplate(),
        ]);
        $this->dispatchQueuedEvent($request, $queued, $this->actor($request));

        return new PatientCommunicationResource($this->load($queued));
    }

    /**
     * Cancels an eligible queued communication using an optimistic version check.
     */
    public function cancel(CancelPatientCommunicationRequest $request, PatientCommunication $communication): PatientCommunicationResource
    {
        $data = $request->validated();
        $cancelled = DB::transaction(function () use ($communication, $data): PatientCommunication {
            $locked = PatientCommunication::query()->lockForUpdate()->findOrFail($communication->getKey());
            abort_if($locked->lock_version !== (int) $data['version'], Response::HTTP_CONFLICT);
            abort_unless($locked->status->canBeCancelled(), Response::HTTP_CONFLICT);

            $locked->status = CommunicationStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $data['reason'] ?? null;
            $locked->lock_version++;
            $locked->save();

            return $locked;
        });

        $this->audit->record($request, 'communication.cancelled', $cancelled, [
            'channel' => $cancelled->channel->value,
        ]);

        return new PatientCommunicationResource($this->load($cancelled));
    }

    /**
     * Applies the consent snapshot and queue state while the communication row is locked in a transaction.
     */
    private function queueLocked(PatientCommunication $communication, mixed $scheduledAt): void
    {
        $this->assertSecureContentBoundary($communication);
        $consent = $this->activeConsentFor($communication);
        abort_unless($consent instanceof ConsentRecord, Response::HTTP_UNPROCESSABLE_ENTITY);

        $communication->consent_record_id = $consent->getKey();
        $communication->status = CommunicationStatus::Queued;
        $communication->scheduled_at = is_string($scheduledAt) ? $scheduledAt : $communication->scheduled_at;
        $communication->queued_at = now();
        $communication->compliance_snapshot = [
            'channel' => $communication->channel->value,
            'consent_id' => $consent->getKey(),
            'consent_type' => $consent->consent_type,
            'verified_at' => now()->toIso8601String(),
        ];
        $communication->lock_version++;
        $communication->save();
    }

    /**
     * Dispatches a post-commit event containing only operational identifiers for durable downstream handoff.
     */
    private function dispatchQueuedEvent(Request $request, PatientCommunication $communication, User $actor): void
    {
        $traceId = $request->attributes->get('trace_id');

        event(new CommunicationQueued(
            communicationId: $communication->getKey(),
            tenantId: $this->tenant()->getKey(),
            actorUserId: $actor->getKey(),
            actorSubject: $actor->oidc_subject,
            traceId: is_string($traceId) ? $traceId : null,
            channel: $communication->channel->value,
            contentClass: $communication->content_class->value,
            usesExternalTemplate: $communication->channel->requiresTemplate(),
        ));
    }

    /**
     * Rejects clinical free text in external channels and requires templates for administrative delivery.
     */
    private function assertSecureContentBoundary(PatientCommunication $communication): void
    {
        if ($communication->channel !== CommunicationChannel::Portal) {
            abort_unless(
                $communication->content_class === CommunicationContentClass::Administrative
                    && is_string($communication->template_key)
                    && in_array($communication->template_key, StorePatientCommunicationRequest::EXTERNAL_TEMPLATE_KEYS, true)
                    && $communication->subject === null
                    && $communication->body === null,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );

            return;
        }

        abort_unless(
            is_string($communication->subject)
                && $communication->subject !== ''
                && is_string($communication->body)
                && $communication->body !== '',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Enforces the elevated capability required to create clinical-link communications.
     */
    private function assertCanWriteContentClass(User $actor, CommunicationContentClass $contentClass): void
    {
        abort_unless(
            $contentClass !== CommunicationContentClass::ClinicalLink
                || $this->permissions->allows($actor, 'communications.clinical.write'),
            Response::HTTP_FORBIDDEN,
        );
    }

    /**
     * Finds the currently valid channel-specific consent for the communication recipient.
     */
    private function activeConsentFor(PatientCommunication $communication): ?ConsentRecord
    {
        return ConsentRecord::query()
            ->where('patient_id', $communication->patient_id)
            ->where('consent_type', 'communications.'.$communication->channel->value)
            ->where('status', ConsentStatus::Active)
            ->where('granted_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('granted_at')
            ->first();
    }

    /**
     * Loads the minimal relations required by the API resource.
     */
    private function load(PatientCommunication $communication): PatientCommunication
    {
        return $communication->load(['patient', 'author']);
    }

    /**
     * Returns the authenticated application user or terminates the request safely.
     */
    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, Response::HTTP_UNAUTHORIZED);

        return $actor;
    }

    /**
     * Returns the fail-closed tenant context resolved by middleware.
     */
    private function tenant(): Tenant
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        abort_unless($tenant instanceof Tenant, Response::HTTP_FORBIDDEN);

        return $tenant;
    }
}
