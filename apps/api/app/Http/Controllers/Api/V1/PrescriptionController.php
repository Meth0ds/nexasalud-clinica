<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\PrescriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePrescriptionRequest;
use App\Http\Requests\Api\V1\TransitionPrescriptionRequest;
use App\Http\Requests\Api\V1\UpdatePrescriptionRequest;
use App\Http\Resources\Api\V1\PrescriptionResource;
use App\Models\Appointment;
use App\Models\PatientAllergy;
use App\Models\Prescription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auditing\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages tenant-scoped prescriptions with controlled drafting, signing and medication-safety checks.
 */
final class PrescriptionController extends Controller
{
    /**
     * Create the prescription controller with privacy-preserving audit recording.
     */
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Return authorized prescriptions using validated, bounded filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Prescription::class);
        $filters = $request->validate([
            'patientId' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(PrescriptionStatus::class)],
            'prescriberId' => ['nullable', 'string'],
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $prescriptions = Prescription::query()
            ->with(['patient.allergies', 'prescriber', 'items', 'appointment'])
            ->when(isset($filters['patientId']), fn ($query) => $query->where('patient_id', $filters['patientId']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['prescriberId']), fn ($query) => $query->where('prescriber_user_id', $filters['prescriberId']))
            ->latest('created_at')
            ->paginate((int) ($filters['perPage'] ?? 30));

        return PrescriptionResource::collection($prescriptions);
    }

    /**
     * Create a draft prescription and its medication items after validating the care context.
     */
    public function store(StorePrescriptionRequest $request): PrescriptionResource
    {
        $data = $request->validated();
        $actor = $this->actor($request);
        $this->validateAppointmentContext($data);

        $prescription = DB::transaction(function () use ($actor, $data): Prescription {
            $prescription = Prescription::create([
                'tenant_id' => $this->tenant()->getKey(),
                'clinic_id' => $data['clinicId'],
                'patient_id' => $data['patientId'],
                'prescriber_user_id' => $actor->getKey(),
                'appointment_id' => $data['appointmentId'] ?? null,
                'clinical_indication' => $data['clinicalIndication'],
                'valid_until' => $data['validUntil'] ?? null,
            ]);

            $this->replaceItems($prescription, $data['items']);

            return $prescription;
        });

        $warnings = $this->allergyWarnings($prescription);
        $this->audit->record($request, 'prescription.created', $prescription, [
            'item_count' => count($data['items']),
            'safety_warning_count' => count($warnings),
        ]);

        return new PrescriptionResource($this->load($prescription));
    }

    /**
     * Return an authorized prescription and record the sensitive access event.
     */
    public function show(Request $request, Prescription $prescription): PrescriptionResource
    {
        Gate::authorize('view', $prescription);
        $this->audit->record($request, 'prescription.viewed', $prescription);

        return new PrescriptionResource($this->load($prescription));
    }

    /**
     * Update a draft prescription under a row lock and expected optimistic-lock version.
     */
    public function update(UpdatePrescriptionRequest $request, Prescription $prescription): PrescriptionResource
    {
        $data = $request->validated();
        abort_unless($prescription->status === PrescriptionStatus::Draft, Response::HTTP_CONFLICT);

        $updated = DB::transaction(function () use ($data, $prescription): Prescription {
            $locked = Prescription::query()->lockForUpdate()->findOrFail($prescription->getKey());
            $this->requireVersion($locked, (int) $data['version']);
            abort_unless($locked->status === PrescriptionStatus::Draft, Response::HTTP_CONFLICT);

            $locked->clinical_indication = $data['clinicalIndication'];
            $locked->appointment_id = $data['appointmentId'] ?? null;
            $locked->valid_until = $data['validUntil'] ?? null;
            $locked->lock_version++;
            $locked->save();
            $this->replaceItems($locked, $data['items']);

            return $locked;
        });

        $warnings = $this->allergyWarnings($updated);
        $this->audit->record($request, 'prescription.updated', $updated, [
            'item_count' => count($data['items']),
            'safety_warning_count' => count($warnings),
            'version' => $updated->lock_version,
        ]);

        return new PrescriptionResource($this->load($updated));
    }

    /**
     * Apply an authorized status transition, requiring recent MFA and justification for safety overrides.
     */
    public function transition(TransitionPrescriptionRequest $request, Prescription $prescription): PrescriptionResource
    {
        $data = $request->validated();
        $this->requireVersion($prescription, (int) $data['version']);
        $next = PrescriptionStatus::from($data['status']);
        $warnings = $this->allergyWarnings($prescription);

        if ($next === PrescriptionStatus::Signed) {
            $this->requireRecentMfa($request);
            $reason = trim((string) ($data['reason'] ?? ''));
            abort_if($warnings !== [] && mb_strlen($reason) < 8, Response::HTTP_CONFLICT);
        }

        $transitioned = $prescription->transitionTo($next, $this->actor($request), $data['reason'] ?? null);

        if ($next === PrescriptionStatus::Sent) {
            $transitioned->external_provider_reference = $data['externalProviderReference'];
            $transitioned->save();
        }

        $this->audit->record($request, 'prescription.status_changed', $transitioned, [
            'status' => $next->value,
            'version' => $transitioned->lock_version,
            'safety_override' => $next === PrescriptionStatus::Signed && $warnings !== [],
        ]);

        return new PrescriptionResource($this->load($transitioned));
    }

    /**
     * Disallow prescription deletion to preserve clinical traceability.
     */
    public function destroy(): never
    {
        abort(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    /**
     * Replace draft medication items in their validated request order.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function replaceItems(Prescription $prescription, array $items): void
    {
        $prescription->items()->get()->each->delete();

        foreach (array_values($items) as $index => $item) {
            $prescription->items()->create([
                'tenant_id' => $prescription->tenant_id,
                'medication_code_system' => $item['codeSystem'],
                'medication_code' => $item['code'],
                'medication_display' => $item['display'],
                'dose_amount' => $item['doseAmount'],
                'dose_unit_code' => $item['doseUnitCode'],
                'route_code' => $item['routeCode'],
                'frequency_code' => $item['frequencyCode'],
                'duration_value' => $item['durationValue'] ?? null,
                'duration_unit' => $item['durationUnit'] ?? null,
                'quantity' => $item['quantity'],
                'quantity_unit_code' => $item['quantityUnitCode'],
                'instructions' => $item['instructions'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Return active patient-allergy medication-code matches as safety warnings.
     *
     * @return list<string>
     */
    private function allergyWarnings(Prescription $prescription): array
    {
        $codes = $prescription->items()->pluck('medication_code')->filter()->all();
        if ($codes === []) {
            return [];
        }

        return PatientAllergy::query()
            ->where('patient_id', $prescription->patient_id)
            ->where('clinical_status', 'active')
            ->whereIn('substance_code', $codes)
            ->pluck('substance_code')
            ->filter(fn ($code): bool => is_string($code))
            ->values()
            ->all();
    }

    /**
     * Ensure an optional appointment belongs to the selected patient and clinic.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateAppointmentContext(array $data): void
    {
        if (! isset($data['appointmentId'])) {
            return;
        }

        $appointment = Appointment::query()->findOrFail($data['appointmentId']);
        abort_if(
            $appointment->patient_id !== $data['patientId'] || $appointment->clinic_id !== $data['clinicId'],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Require a recent, verified multi-factor authentication context before prescription signing.
     */
    private function requireRecentMfa(Request $request): void
    {
        $methods = $request->session()->get('auth.assurance.amr', []);
        $authenticatedAt = $request->session()->get('auth.assurance.authenticated_at');
        $hasMfa = is_array($methods) && array_intersect($methods, ['mfa', 'otp', 'totp', 'webauthn', 'fido']) !== [];
        $isRecent = is_int($authenticatedAt) && $authenticatedAt >= now()->subMinutes(15)->getTimestamp();

        abort_unless($hasMfa && $isRecent, Response::HTTP_FORBIDDEN);
    }

    /**
     * Load only the relations required by an authorized prescription response.
     */
    private function load(Prescription $prescription): Prescription
    {
        return $prescription->load(['patient.allergies', 'prescriber', 'items', 'appointment']);
    }

    /**
     * Reject a stale prescription mutation when its optimistic-lock version does not match.
     */
    private function requireVersion(Prescription $prescription, int $version): void
    {
        abort_if($prescription->lock_version !== $version, Response::HTTP_CONFLICT);
    }

    /**
     * Return the authenticated local actor or reject anonymous prescription mutations.
     */
    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, Response::HTTP_UNAUTHORIZED);

        return $actor;
    }

    /**
     * Return the active tenant context or reject contextless prescription creation.
     */
    private function tenant(): Tenant
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        abort_unless($tenant instanceof Tenant, Response::HTTP_FORBIDDEN);

        return $tenant;
    }
}
