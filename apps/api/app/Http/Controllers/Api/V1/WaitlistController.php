<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\WaitlistStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OfferWaitlistRequest;
use App\Http\Requests\Api\V1\StoreWaitlistRequest;
use App\Http\Resources\Api\V1\WaitlistEntryResource;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Support\Auditing\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class WaitlistController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

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
            ->with(['patient', 'appointmentType', 'preferredClinician', 'clinic'])
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['clinicId']), fn ($query) => $query->where('clinic_id', $filters['clinicId']))
            ->when(isset($filters['patientId']), fn ($query) => $query->where('patient_id', $filters['patientId']))
            ->orderBy('priority')
            ->oldest('created_at')
            ->paginate((int) ($filters['perPage'] ?? 50));

        return WaitlistEntryResource::collection($entries);
    }

    public function store(StoreWaitlistRequest $request): WaitlistEntryResource
    {
        $data = $request->validated();
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

    public function show(Request $request, WaitlistEntry $waitlistEntry): WaitlistEntryResource
    {
        Gate::authorize('view', $waitlistEntry);
        $this->audit->record($request, 'waitlist.viewed', $waitlistEntry);

        return new WaitlistEntryResource($this->load($waitlistEntry));
    }

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

    public function offer(OfferWaitlistRequest $request, WaitlistEntry $waitlistEntry): WaitlistEntryResource
    {
        $data = $request->validated();
        $entry = DB::transaction(function () use ($data, $waitlistEntry): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($waitlistEntry->getKey());
            abort_if($entry->lock_version !== (int) $data['version'], Response::HTTP_CONFLICT);
            abort_unless($entry->status === WaitlistStatus::Waiting, Response::HTTP_CONFLICT);

            $entry->status = WaitlistStatus::Offered;
            $entry->offered_at = now();
            $entry->offer_expires_at = now()->addMinutes((int) $data['expiresInMinutes']);
            $entry->fulfilled_appointment_id = $data['appointmentId'] ?? null;
            $entry->lock_version++;
            $entry->save();

            return $entry;
        });

        $this->audit->record($request, 'waitlist.offer_created', $entry, [
            'expires_in_minutes' => (int) $data['expiresInMinutes'],
        ]);

        return new WaitlistEntryResource($this->load($entry));
    }

    public function destroy(Request $request, WaitlistEntry $waitlistEntry): Response
    {
        Gate::authorize('update', $waitlistEntry);
        abort_if(in_array($waitlistEntry->status, [WaitlistStatus::Booked, WaitlistStatus::Cancelled], true), Response::HTTP_CONFLICT);

        $waitlistEntry->forceFill([
            'status' => WaitlistStatus::Cancelled,
            'lock_version' => $waitlistEntry->lock_version + 1,
        ])->save();
        $this->audit->record($request, 'waitlist.cancelled', $waitlistEntry);

        return response()->noContent();
    }

    private function load(WaitlistEntry $entry): WaitlistEntry
    {
        return $entry->load(['patient', 'appointmentType', 'preferredClinician', 'clinic']);
    }

    private function tenant(): Tenant
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        abort_unless($tenant instanceof Tenant, Response::HTTP_FORBIDDEN);

        return $tenant;
    }
}
