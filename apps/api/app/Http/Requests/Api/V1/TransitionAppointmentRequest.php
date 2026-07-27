<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Validation\Rule;

class TransitionAppointmentRequest extends TenantFormRequest
{
    public function authorize(): bool
    {
        $appointment = $this->route('appointment');

        return $appointment instanceof Appointment && $this->authenticatedUser()->can('update', $appointment);
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
            'reason' => ['nullable', 'required_if:status,cancelled,no_show', 'string', 'min:3', 'max:500'],
        ];
    }
}
