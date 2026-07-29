<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Appointment;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * Validate a clinician-initiated, versioned appointment rescheduling request.
 */
class RescheduleAppointmentRequest extends TenantFormRequest
{
    /**
     * Determine whether the authenticated user may update the routed appointment.
     */
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && $this->authenticatedUser()->can('update', $appointment);
    }

    /**
     * Return tenant-scoped validation rules for a replacement appointment period and resources.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'version' => ['required', 'integer', 'min:1'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
            'timezone' => ['required', 'timezone:all'],
            'roomId' => ['nullable', 'string', Rule::exists('rooms', 'id')->where(fn (Builder $query) => $query->where('tenant_id', $tenant))],
            'practitionerId' => ['nullable', 'string', Rule::exists('memberships', 'user_id')->where(fn (Builder $query) => $query->where('tenant_id', $tenant)->where('is_active', true))],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
