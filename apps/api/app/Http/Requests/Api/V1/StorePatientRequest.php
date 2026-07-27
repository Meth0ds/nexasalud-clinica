<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Patient;
use Closure;

class StorePatientRequest extends TenantFormRequest
{
    public function authorize(): bool
    {
        return $this->authenticatedUser()->can('create', Patient::class);
    }

    public function rules(): array
    {
        return [
            'medicalRecordNumber' => ['required', 'string', 'max:80', $this->uniqueMedicalRecordNumber()],
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:160'],
            'birthDate' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'preferredLanguage' => ['required', 'string', 'in:es,ca,eu,gl,en'],
        ];
    }

    private function uniqueMedicalRecordNumber(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $exists = Patient::query()->where('medical_record_hash', Patient::blindIndex($this->tenantId(), $value))->exists();
            if ($exists) {
                $fail('Ya existe una ficha con ese identificador en el centro.');
            }
        };
    }
}
