<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClinicalNoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClinicalNoteRequest;
use App\Http\Resources\Api\V1\ClinicalNoteResource;
use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Patient;
use App\Models\User;
use App\Support\Auditing\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages authorized, append-only clinical notes and requires recent MFA for signing.
 */
final class ClinicalNoteController extends Controller
{
    /**
     * Create the clinical-note controller with privacy-preserving audit recording.
     */
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Return the authorized patient's paginated clinical-note timeline.
     */
    public function index(Request $request, Patient $patient): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ClinicalNote::class, $patient]);
        $filters = $request->validate(['perPage' => ['nullable', 'integer', 'between:1,100']]);

        $notes = ClinicalNote::query()
            ->with('author')
            ->where('patient_id', $patient->getKey())
            ->where('tenant_id', $patient->tenant_id)
            ->latest('created_at')
            ->paginate((int) ($filters['perPage'] ?? 50));

        $this->audit->record($request, 'clinical_note.timeline_viewed', $patient, [
            'result_count' => $notes->count(),
        ]);

        return ClinicalNoteResource::collection($notes);
    }

    /**
     * Create an authorized draft clinical note from validated minimum-necessary content.
     */
    public function store(StoreClinicalNoteRequest $request, Patient $patient): ClinicalNoteResource
    {
        $data = $request->validated();
        $note = $this->create($request, $patient, $data, ClinicalNoteStatus::Draft);

        $this->audit->record($request, 'clinical_note.drafted', $note, [
            'appointment_id_present' => $note->appointment_id !== null,
            'content_length' => mb_strlen($note->content),
        ]);

        return new ClinicalNoteResource($note->load('author'));
    }

    /**
     * Create a signed clinical note only after recent multi-factor authentication.
     */
    public function sign(StoreClinicalNoteRequest $request, Patient $patient): ClinicalNoteResource
    {
        $this->requireRecentMfa($request);
        Gate::authorize('sign', [ClinicalNote::class, $patient]);
        $data = $request->validated();
        $note = $this->create($request, $patient, $data, ClinicalNoteStatus::Signed);

        $this->audit->record($request, 'clinical_note.signed', $note, [
            'appointment_id_present' => $note->appointment_id !== null,
            'content_length' => mb_strlen($note->content),
            'mfa_recent' => true,
        ]);

        return new ClinicalNoteResource($note->load('author'));
    }

    /**
     * Create a clinical note after validating related records and the authenticated author.
     *
     * @param  array<string, mixed>  $data
     */
    private function create(Request $request, Patient $patient, array $data, ClinicalNoteStatus $status): ClinicalNote
    {
        $this->validateRelations($patient, $data);
        $actor = $request->user();
        abort_unless($actor instanceof User, Response::HTTP_UNAUTHORIZED);

        return ClinicalNote::create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->getKey(),
            'appointment_id' => $data['appointmentId'] ?? null,
            'author_user_id' => $actor->getKey(),
            'supersedes_id' => $data['supersedesId'] ?? null,
            'status' => $status,
            'content' => trim($data['content']),
            'signature_context' => $status === ClinicalNoteStatus::Signed ? $this->signatureContext($request) : null,
            'signed_at' => $status === ClinicalNoteStatus::Signed ? now() : null,
        ]);
    }

    /**
     * Ensure optional appointment and superseded-note references belong to the same patient.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateRelations(Patient $patient, array $data): void
    {
        if (isset($data['appointmentId'])) {
            $appointment = Appointment::query()->findOrFail($data['appointmentId']);
            abort_if(
                $appointment->tenant_id !== $patient->tenant_id
                    || $appointment->patient_id !== $patient->getKey(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (isset($data['supersedesId'])) {
            $superseded = ClinicalNote::query()->findOrFail($data['supersedesId']);
            abort_if(
                $superseded->tenant_id !== $patient->tenant_id
                    || $superseded->patient_id !== $patient->getKey(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    /**
     * Require a recent, verified multi-factor authentication context for clinical signing.
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
     * Capture only allowlisted authentication assurance values as note-signature context.
     *
     * @return array{amr: list<string>, authenticated_at: int|null}
     */
    private function signatureContext(Request $request): array
    {
        $methods = $request->session()->get('auth.assurance.amr', []);
        $allowedMethods = ['mfa', 'otp', 'totp', 'webauthn', 'fido'];

        return [
            'amr' => is_array($methods)
                ? array_values(array_filter($methods, fn ($method): bool => is_string($method) && in_array($method, $allowedMethods, true)))
                : [],
            'authenticated_at' => is_int($request->session()->get('auth.assurance.authenticated_at'))
                ? $request->session()->get('auth.assurance.authenticated_at')
                : null,
        ];
    }
}
