<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Prescription;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class StorePrescriptionRequest extends TenantFormRequest
{
    public function authorize(): bool { return $this->authenticatedUser()->can('create', Prescription::class); }
    public function rules(): array
    {
        $tenant = $this->tenantId();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where(fn (Builder $query) => $query->where('tenant_id', $tenant));
        return [
            'clinicId' => ['required', 'string', $exists('clinics')],
            'patientId' => ['required', 'string', $exists('patients')],
            'appointmentId' => ['nullable', 'string', $exists('appointments')],
            'clinicalIndication' => ['required', 'string', 'min:3', 'max:1000'],
            'validUntil' => ['nullable', 'date', 'after_or_equal:today'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.codeSystem' => ['required', 'string', 'max:120'],
            'items.*.code' => ['required', 'string', 'max:120'],
            'items.*.display' => ['required', 'string', 'max:255'],
            'items.*.doseAmount' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'items.*.doseUnitCode' => ['required', 'string', 'max:40'],
            'items.*.routeCode' => ['required', 'string', 'max:80'],
            'items.*.frequencyCode' => ['required', 'string', 'max:80'],
            'items.*.durationValue' => ['nullable', 'integer', 'between:1,3650'],
            'items.*.durationUnit' => ['nullable', 'string', 'max:30'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'items.*.quantityUnitCode' => ['required', 'string', 'max:40'],
            'items.*.instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
