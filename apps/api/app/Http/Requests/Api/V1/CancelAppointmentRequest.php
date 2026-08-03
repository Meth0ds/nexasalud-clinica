<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Appointment;

/**
 * Validate an authorized, versioned appointment cancellation request.
 */
final class CancelAppointmentRequest extends TenantFormRequest
{
    /**
     * Determine whether the authenticated user may cancel the routed appointment.
     */
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment
            && $this->authenticatedUser()->can('delete', $appointment);
    }

    /**
     * Return the optimistic-concurrency and audit-reason validation rules.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
