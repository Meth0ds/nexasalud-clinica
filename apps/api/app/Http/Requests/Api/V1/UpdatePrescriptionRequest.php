<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Prescription;

/**
 * Validate a versioned update to an existing prescription without changing its ownership.
 */
class UpdatePrescriptionRequest extends StorePrescriptionRequest
{
    /**
     * Determine whether the authenticated user may update the routed prescription.
     */
    public function authorize(): bool
    {
        $prescription = $this->route('prescription');

        return $prescription instanceof Prescription && $this->authenticatedUser()->can('update', $prescription);
    }

    /**
     * Return prescription validation rules adjusted for an existing immutable owner and clinic.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['clinicId'], $rules['patientId']);
        $rules['version'] = ['required', 'integer', 'min:1'];

        return $rules;
    }
}
