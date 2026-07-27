<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Patient;

class UpdatePatientRequest extends TenantFormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient && $this->authenticatedUser()->can('update', $patient);
    }

    public function rules(): array
    {
        return [
            'firstName' => ['sometimes', 'required', 'string', 'max:100'],
            'lastName' => ['sometimes', 'required', 'string', 'max:160'],
            'birthDate' => ['sometimes', 'required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'preferredLanguage' => ['sometimes', 'required', 'string', 'in:es,ca,eu,gl,en'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
