<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Prescription;

class UpdatePrescriptionRequest extends StorePrescriptionRequest
{
    public function authorize(): bool
    {
        $prescription = $this->route('prescription');

        return $prescription instanceof Prescription && $this->authenticatedUser()->can('update', $prescription);
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['clinicId'], $rules['patientId']);
        $rules['version'] = ['required', 'integer', 'min:1'];

        return $rules;
    }
}
