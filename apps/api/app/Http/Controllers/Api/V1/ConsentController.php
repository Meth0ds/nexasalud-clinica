<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConsentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConsentRecordResource;
use App\Models\ConsentRecord;
use App\Models\Patient;
use App\Models\User;
use App\Support\Auditing\AuditRecorder;
use App\Support\Authorization\PermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages tenant-scoped patient consent records with versioned grants and audited withdrawals.
 */
final class ConsentController extends Controller
{
    /**
     * Create the consent controller with permission and audit dependencies.
     */
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Return the authorized consent records of a patient linked to the active clinic.
     */
    public function index(Request $request, Patient $patient): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        abort_unless($this->permissions->allows($actor, 'patients.read'), Response::HTTP_FORBIDDEN);
        $this->requirePatientInCurrentClinic($patient);

        $records = ConsentRecord::query()
            ->where('patient_id', $patient->getKey())
            ->where('tenant_id', $this->currentTenant()->getKey())
            ->latest('granted_at')
            ->get();

        $this->audit->record($request, 'consents.viewed', $patient, [
            'result_count' => $records->count(),
        ]);

        return ConsentRecordResource::collection($records);
    }

    /**
     * Record a new versioned consent grant for a patient linked to the active clinic.
     */
    public function store(Request $request, Patient $patient): ConsentRecordResource
    {
        $actor = $this->actor($request);
        abort_unless($this->permissions->allows($actor, 'patients.write'), Response::HTTP_FORBIDDEN);
        $this->requirePatientInCurrentClinic($patient);

        $data = $request->validate([
            'consentType' => ['required', 'string', 'max:100'],
            'documentVersion' => ['required', 'string', 'max:60'],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ]);

        $record = ConsentRecord::create([
            'tenant_id' => $this->currentTenant()->getKey(),
            'patient_id' => $patient->getKey(),
            'consent_type' => $data['consentType'],
            'document_version' => $data['documentVersion'],
            'status' => ConsentStatus::Active,
            'recorded_by' => $actor->getKey(),
            'granted_at' => now(),
            'expires_at' => isset($data['expiresAt']) ? CarbonImmutable::parse($data['expiresAt'])->utc() : null,
        ]);

        $this->audit->record($request, 'consent.granted', $record, [
            'consent_type' => $record->consent_type,
            'document_version' => $record->document_version,
        ]);

        return new ConsentRecordResource($record);
    }

    /**
     * Withdraw an active consent record with an audited, encrypted reason.
     */
    public function withdraw(Request $request, Patient $patient, ConsentRecord $consentRecord): ConsentRecordResource
    {
        $actor = $this->actor($request);
        abort_unless($this->permissions->allows($actor, 'patients.write'), Response::HTTP_FORBIDDEN);
        $this->requirePatientInCurrentClinic($patient);
        abort_unless(
            $consentRecord->patient_id === $patient->getKey()
                && $consentRecord->tenant_id === $this->currentTenant()->getKey(),
            Response::HTTP_NOT_FOUND,
        );

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        abort_unless($consentRecord->status === ConsentStatus::Active, Response::HTTP_CONFLICT);

        $consentRecord->forceFill([
            'status' => ConsentStatus::Withdrawn,
            'withdrawn_at' => now(),
            'withdrawal_reason' => $data['reason'] ?? null,
        ])->save();

        $this->audit->record($request, 'consent.withdrawn', $consentRecord, [
            'consent_type' => $consentRecord->consent_type,
        ]);

        return new ConsentRecordResource($consentRecord);
    }

    /**
     * Return the authenticated application user or reject anonymous consent mutations.
     */
    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, Response::HTTP_UNAUTHORIZED);

        return $actor;
    }
}
