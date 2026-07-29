<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Validation\Rule;

/**
 * Validate an optimistic-concurrency request to transition an appointment's status.
 */
class TransitionAppointmentRequest extends TenantFormRequest
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
     * Return validation rules for an appointment status transition and its required rationale.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
            'reason' => ['nullable', 'required_if:status,cancelled,no_show', 'string', 'min:3', 'max:500'],
        ];
    }
}
