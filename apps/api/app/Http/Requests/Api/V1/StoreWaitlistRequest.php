<?php

namespace App\Http\Requests\Api\V1;

use App\Models\WaitlistEntry;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * Validate creation of a tenant-scoped patient waitlist entry.
 */
class StoreWaitlistRequest extends TenantFormRequest
{
    /**
     * Determine whether the authenticated user may create waitlist entries.
     */
    public function authorize(): bool
    {
        return $this->authenticatedUser()->can('create', WaitlistEntry::class);
    }

    /**
     * Return tenant-scoped validation rules for a waitlist entry and its preferences.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where(fn (Builder $query) => $query->where('tenant_id', $tenant));

        return [
            'clinicId' => ['required', 'string', $exists('clinics')],
            'patientId' => ['required', 'string', $exists('patients')],
            'appointmentTypeId' => ['required', 'string', $exists('appointment_types')],
            'practitionerId' => ['nullable', 'string', Rule::exists('memberships', 'user_id')->where(fn (Builder $query) => $query->where('tenant_id', $tenant)->where('is_active', true))],
            'desiredFrom' => ['required', 'date'],
            'desiredTo' => ['required', 'date', 'after:desiredFrom'],
            'preferredDays' => ['nullable', 'array', 'max:7'],
            'preferredDays.*' => ['integer', 'between:1,7', 'distinct'],
            'priority' => ['required', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
