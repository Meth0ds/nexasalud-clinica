<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePatientRequest;
use App\Http\Requests\Api\V1\UpdatePatientRequest;
use App\Http\Resources\Api\V1\PatientResource;
use App\Models\Patient;
use App\Models\Tenant;
use App\Support\Auditing\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class PatientController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Patient::class);

        $filters = $request->validate([
            'identifier' => ['nullable', 'string', 'max:80'],
            'active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $tenant = $this->tenant();
        $patients = Patient::query()
            ->with($this->relationships())
            ->when(
                isset($filters['identifier']),
                fn ($query) => $query->where(
                    'medical_record_hash',
                    Patient::blindIndex((string) $tenant->getKey(), $filters['identifier']),
                ),
            )
            ->when(
                array_key_exists('active', $filters),
                fn ($query) => $query->where('is_active', (bool) $filters['active']),
            )
            ->latest('created_at')
            ->paginate((int) ($filters['perPage'] ?? 30));

        $this->audit->record($request, 'patient.directory_viewed', metadata: [
            'filtered_by_identifier' => isset($filters['identifier']),
            'result_count' => $patients->count(),
        ]);

        return PatientResource::collection($patients);
    }

    public function store(StorePatientRequest $request): PatientResource
    {
        $data = $request->validated();
        $patient = DB::transaction(fn (): Patient => Patient::create([
            'tenant_id' => $this->tenant()->getKey(),
            'medical_record_number' => $data['medicalRecordNumber'],
            'first_name' => $data['firstName'],
            'last_name' => $data['lastName'],
            'birth_date' => $data['birthDate'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'preferred_language' => $data['preferredLanguage'],
        ]));

        $this->audit->record($request, 'patient.created', $patient);

        return new PatientResource($patient->load($this->relationships()));
    }

    public function show(Request $request, Patient $patient): PatientResource
    {
        Gate::authorize('view', $patient);
        $this->audit->record($request, 'patient.viewed', $patient, ['purpose' => 'care_operations']);

        return new PatientResource($patient->load($this->relationships()));
    }

    public function update(UpdatePatientRequest $request, Patient $patient): PatientResource
    {
        $data = $request->validated();
        $map = [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'birthDate' => 'birth_date',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'address',
            'preferredLanguage' => 'preferred_language',
            'isActive' => 'is_active',
        ];

        DB::transaction(function () use ($data, $map, $patient): void {
            $locked = Patient::query()->lockForUpdate()->findOrFail($patient->getKey());
            foreach ($map as $input => $attribute) {
                if (array_key_exists($input, $data)) {
                    $locked->setAttribute($attribute, $data[$input]);
                }
            }
            $locked->save();
        });

        $patient->refresh();
        $this->audit->record($request, 'patient.updated', $patient, [
            'changed_field_count' => count($data),
        ]);

        return new PatientResource($patient->load($this->relationships()));
    }

    public function destroy(Request $request, Patient $patient): Response
    {
        Gate::authorize('update', $patient);
        abort_unless($patient->is_active, Response::HTTP_CONFLICT);

        $patient->forceFill(['is_active' => false])->save();
        $this->audit->record($request, 'patient.deactivated', $patient);

        return response()->noContent();
    }

    /** @return array<int|string, mixed> */
    private function relationships(): array
    {
        return [
            'coverages.insurer',
            'appointments' => fn ($query) => $query->with('clinician')->latest('starts_at')->limit(12),
            'consentRecords',
            'allergies',
        ];
    }

    private function tenant(): Tenant
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        abort_unless($tenant instanceof Tenant, Response::HTTP_FORBIDDEN);

        return $tenant;
    }
}
