<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Appointment;
use Illuminate\Validation\Rule;

/**
 * Validate a versioned partial update to an appointment's mutable details.
 */
class UpdateAppointmentRequest extends TenantFormRequest
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
     * Return validation rules for mutable appointment attributes and optimistic concurrency.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'mode' => ['sometimes', Rule::in(['in-person', 'video', 'phone'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
