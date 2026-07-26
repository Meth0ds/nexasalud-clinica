<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Appointment;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends TenantFormRequest
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
            'mode' => ['sometimes', Rule::in(['in-person', 'video', 'phone'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
