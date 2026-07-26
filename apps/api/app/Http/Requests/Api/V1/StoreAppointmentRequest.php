<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Appointment;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends TenantFormRequest
{
    public function authorize(): bool { return $this->authenticatedUser()->can('create', Appointment::class); }

    public function rules(): array
    {
        $tenant = $this->tenantId();
        $tenantExists = fn (string $table) => Rule::exists($table, 'id')->where(fn (Builder $query) => $query->where('tenant_id', $tenant));

        return [
            'clinicId' => ['required', 'string', $tenantExists('clinics')],
            'patientId' => ['required', 'string', $tenantExists('patients')],
            'practitionerId' => ['required', 'string', Rule::exists('memberships', 'user_id')->where(fn (Builder $query) => $query->where('tenant_id', $tenant)->where('is_active', true))],
            'roomId' => ['nullable', 'string', $tenantExists('rooms')],
            'appointmentTypeId' => ['required', 'string', $tenantExists('appointment_types')],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
            'timezone' => ['required', 'timezone:all'],
            'mode' => ['required', Rule::in(['in-person', 'video', 'phone'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
